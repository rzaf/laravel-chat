<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around ffprobe / ffmpeg (needs the binaries installed on the server).
 * Every method fails soft: a broken or exotic file must never break the upload.
 */
class MediaProbeService
{
    /**
     * @return array{kind: ?string, width: ?int, height: ?int, duration: ?float, has_cover: bool}
     *         kind is 'video' | 'audio' | null (null = ffprobe could not read the file)
     */
    public function probe(string $path): array
    {
        $result = ['kind' => null, 'width' => null, 'height' => null, 'duration' => null, 'has_cover' => false];

        $json = $this->run([
            $this->bin('ffprobe'),
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $path,
        ]);
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return $result;
        }

        $streams = collect($data['streams'] ?? []);

        // mp3 files with cover art contain a "video" stream flagged attached_pic: ignore it
        $video = $streams->first(
            fn($s) => ($s['codec_type'] ?? null) === 'video' && empty($s['disposition']['attached_pic'])
        );
        $audio = $streams->first(fn($s) => ($s['codec_type'] ?? null) === 'audio');

        // embedded album art (reuses this same ffprobe call, no second process needed)
        $result['has_cover'] = $streams->contains(
            fn($s) => ($s['codec_type'] ?? null) === 'video' && !empty($s['disposition']['attached_pic'])
        );

        if ($video) {
            $result['kind'] = 'video';
            $w = (int) ($video['width'] ?? 0);
            $h = (int) ($video['height'] ?? 0);

            // phone videos are often stored sideways + a rotation flag: swap so w/h match what people see
            if (abs($this->rotation($video)) % 180 === 90) {
                [$w, $h] = [$h, $w];
            }
            $result['width'] = $w ?: null;
            $result['height'] = $h ?: null;
        } elseif ($audio) {
            $result['kind'] = 'audio';
        }

        $duration = (float) ($data['format']['duration'] ?? 0);
        if ($duration <= 0 && $video) {
            $duration = (float) ($video['duration'] ?? 0);
        }
        if ($duration <= 0 && $audio) {
            $duration = (float) ($audio['duration'] ?? 0);
        }
        // Browser MediaRecorder .webm (voice messages) has no duration header: decode to measure it
        if ($duration <= 0 && $result['kind'] !== null) {
            $duration = $this->measureByDecoding($path);
        }

        $result['duration'] = $duration > 0 ? round($duration, 3) : null;

        return $result;
    }

    /** Returns JPEG bytes of one frame (max $maxWidth px wide, correctly rotated) or null. */
    public function extractFrame(string $path, ?float $duration = null, int $maxWidth = 480): ?string
    {
        // ~1s in avoids the black first frame many videos have; short clips use the very first frame
        $candidates = ($duration !== null && $duration > 2) ? [1.0, 0.0] : [0.0];

        foreach ($candidates as $at) {
            $tmp = sys_get_temp_dir() . '/' . Str::uuid() . '.jpg';
            try {
                $this->run([
                    $this->bin('ffmpeg'),
                    '-v',
                    'error',
                    '-y',
                    '-ss',
                    (string) $at,
                    '-i',
                    $path,
                    '-frames:v',
                    '1',
                    '-vf',
                    "scale='min({$maxWidth},iw)':-2",
                    '-q:v',
                    '4',
                    $tmp,
                ]);
                if (is_file($tmp) && filesize($tmp) > 0) {
                    return file_get_contents($tmp);
                }
            } finally {
                @unlink($tmp);
            }
        }

        return null;
    }

    private function rotation(array $stream): int
    {
        if (isset($stream['tags']['rotate'])) {
            return (int) $stream['tags']['rotate'];
        }
        foreach ($stream['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                return (int) $side['rotation'];
            }
        }
        return 0;
    }

    private function measureByDecoding(string $path): float
    {
        $p = new Process([$this->bin('ffmpeg'), '-v', 'error', '-stats', '-i', $path, '-f', 'null', '-']);
        $p->setTimeout(60);
        try {
            $p->run();
        } catch (\Throwable $e) {
            report($e);
            return 0.0;
        }

        if (preg_match_all('/time=(\d+):(\d+):(\d+(?:\.\d+)?)/', $p->getErrorOutput(), $m, PREG_SET_ORDER)) {
            $last = end($m);
            return $last[1] * 3600 + $last[2] * 60 + (float) $last[3];
        }
        return 0.0;
    }

    private function run(array $command): ?string
    {
        $p = new Process($command);
        $p->setTimeout(60);
        try {
            $p->run();
        } catch (\Throwable $e) {
            report($e); // e.g. ffmpeg not installed / timeout
            return null;
        }
        return $p->isSuccessful() ? $p->getOutput() : null;
    }

    private function bin(string $name): string
    {
        return config("services.ffmpeg.{$name}", $name);
    }

    /**
     * Embedded cover art as a clean JPEG (max $maxWidth px wide).
     *
     * Many covers (e.g. music-video stills) are letterboxed: the picture sits inside bars that are
     * transparent (PNG) or a flat colour. Converting a transparent PNG straight to JPEG turns those
     * bars gray, so we extract losslessly (PNG keeps the alpha), trim the bars, flatten the rest
     * onto black and only then save the JPEG. Falls back to a plain ffmpeg conversion.
     */
    public function extractAudioCoverArt(string $path, int $maxWidth = 480): ?string
    {
        $tmp = sys_get_temp_dir() . '/' . Str::uuid() . '.png';
        try {
            $this->run([
                $this->bin('ffmpeg'), '-v', 'error', '-y',
                '-i', $path,
                '-an', '-frames:v', '1',
                '-vf', "scale='min(1200,iw)':-2",   // keep enough resolution for an accurate trim
                $tmp,
            ]);

            if (is_file($tmp) && filesize($tmp) > 0) {
                $clean = $this->cleanCover(file_get_contents($tmp), $maxWidth);
                if ($clean !== null) {
                    return $clean;
                }
            }
        } finally {
            @unlink($tmp);
        }

        return $this->extractAudioCoverArtAsJpeg($path, $maxWidth);
    }

    /** Trim transparent / flat-colour borders, flatten alpha onto black, resize, encode JPEG. */
    private function cleanCover(string $bytes, int $maxWidth): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $im = new \Imagick();
            $im->readImageBlob($bytes);
            $origW = $im->getImageWidth();
            $origH = $im->getImageHeight();

            $trimmed = clone $im;
            $trimmed->trimImage($im->getQuantumRange()['quantumRangeLong'] * 0.06);
            $trimmed->setImagePage(0, 0, 0, 0);

            // ignore a trim that would leave (almost) nothing, e.g. an all-black cover
            if ($trimmed->getImageWidth() >= $origW * 0.2 && $trimmed->getImageHeight() >= $origH * 0.2) {
                $im = $trimmed;
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();

            // JPEG has no alpha: composite on black instead of letting transparent areas turn gray
            $canvas = new \Imagick();
            $canvas->newImage($w, $h, new \ImagickPixel('black'));
            $canvas->compositeImage($im, \Imagick::COMPOSITE_OVER, 0, 0);

            if ($w > $maxWidth) {
                $canvas->thumbnailImage($maxWidth, 0);
            }
            $canvas->setImageFormat('jpeg');
            $canvas->setImageCompressionQuality(75);
            $canvas->stripImage();

            return $canvas->getImageBlob();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function extractAudioCoverArtAsJpeg(string $path, int $maxWidth): ?string
    {
        $tmp = sys_get_temp_dir() . '/' . Str::uuid() . '.jpg';
        try {
            $this->run([
                $this->bin('ffmpeg'), '-v', 'error', '-y',
                '-i', $path,
                '-an', '-frames:v', '1',
                '-vf', "scale='min({$maxWidth},iw)':-2",
                '-q:v', '4',
                $tmp,
            ]);

            if (is_file($tmp) && filesize($tmp) > 0) {
                return file_get_contents($tmp);
            }
        } finally {
            @unlink($tmp);
        }

        return null;
    }
}