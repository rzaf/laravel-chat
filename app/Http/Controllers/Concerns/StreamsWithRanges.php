<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a local file with HTTP Range support, answering every range with a
 * small, capped piece (default 2 MiB) instead of "everything until the end".
 *
 * Usage in the media.private action (after your signature/permission checks):
 *   return $this->rangedResponse($request, Storage::disk($m->disk)->path($m->path), $m->mime_type);
 */
trait StreamsWithRanges
{
    protected function rangedResponse(
        Request $request,
        string $absolutePath,
        string $mime,
        int $maxChunk = 2 * 1024 * 1024,
    ): Response {
        abort_unless(is_file($absolutePath), 404);

        $size = filesize($absolutePath);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($absolutePath)) . ' GMT',
            'Content-Disposition' => 'inline',
        ];

        // single range only: "bytes=N-", "bytes=N-M", "bytes=-N" (suffix = last N bytes)
        if (
            preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) $request->header('Range')), $m)
            && ($m[1] !== '' || $m[2] !== '')
        ) {

            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $end);
                }
            }

            if ($start > $end || $start >= $size) {
                return response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }

            // the important part: answer with at most $maxChunk bytes.
            // A server may legally return less than requested; the browser just asks again.
            $end = min($end, $start + $maxChunk - 1);

            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = $length;

        return new StreamedResponse(function () use ($absolutePath, $start, $length) {
            $fh = fopen($absolutePath, 'rb');
            fseek($fh, $start);
            $left = $length;

            while ($left > 0 && !feof($fh) && !connection_aborted()) {
                $buf = fread($fh, min(65536, $left));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                $left -= strlen($buf);
                flush();
            }
            fclose($fh);
        }, $status, $headers);
    }
}
