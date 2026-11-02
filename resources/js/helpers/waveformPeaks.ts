/**
 * Decodes an audio URL into a small array of normalised peaks (0..1).
 * Results are cached per attachment id so each file is decoded at most once.
 * If decoding fails (CORS, unsupported codec, expired URL) we fall back to
 * deterministic pseudo-peaks so the UI still renders something stable.
 */
const cache = new Map<number | string, number[]>();
const inflight = new Map<number | string, Promise<number[]>>();

export function fallbackPeaks(seed: number | string, bars = 512): number[] {
    let s = 0;
    for (const ch of String(seed)) s = (s * 31 + ch.charCodeAt(0)) >>> 0;
    const out: number[] = [];
    for (let i = 0; i < bars; i++) {
        s = (s * 1664525 + 1013904223) >>> 0;
        const noise = (s / 0xffffffff) * 0.6;
        const envelope = 0.35 + 0.35 * Math.abs(Math.sin(i * 0.37));
        out.push(Math.min(1, envelope + noise * 0.5));
    }
    return out;
}

export function getCachedPeaks(id: number | string): number[] | undefined {
    return cache.get(id);
}

export async function loadPeaks(id: number | string, url: string, bars = 512): Promise<number[]> {
    const hit = cache.get(id);
    if (hit) return hit;
    const pending = inflight.get(id);
    if (pending) return pending;

    const job = (async () => {
        try {
            const res = await fetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const buf = await res.arrayBuffer();
            const ctx = new (window.AudioContext || (window as any).webkitAudioContext)();
            try {
                const audio = await ctx.decodeAudioData(buf);
                const data = audio.getChannelData(0);
                const block = Math.max(1, Math.floor(data.length / bars));
                const peaks: number[] = [];
                for (let i = 0; i < bars; i++) {
                    let max = 0;
                    const start = i * block;
                    // stride through the block; exact max is not needed visually
                    const step = Math.max(1, Math.floor(block / 200));
                    for (let j = 0; j < block; j += step) {
                        const v = Math.abs(data[start + j] || 0);
                        if (v > max) max = v;
                    }
                    peaks.push(max);
                }
                const top = Math.max(...peaks, 0.0001);
                // gentle curve so quiet passages still show detail
                const normalised = peaks.map((p) => Math.max(0.06, Math.pow(p / top, 0.75)));
                cache.set(id, normalised);
                return normalised;
            } finally {
                ctx.close();
            }
        } catch (e) {
            console.warn(`[waveform:${id}] decode failed, using fallback peaks`, e);
            const fb = fallbackPeaks(id, bars);
            cache.set(id, fb);
            return fb;
        } finally {
            inflight.delete(id);
        }
    })();

    inflight.set(id, job);
    return job;
}

/** Reduce (or stretch) a peaks array to `n` buckets, keeping the max of each bucket. */
export function resamplePeaks(peaks: number[], n: number): number[] {
    if (!peaks.length) return Array(n).fill(0.2);
    const out: number[] = [];
    for (let i = 0; i < n; i++) {
        const a = Math.floor((i / n) * peaks.length);
        const b = Math.max(a + 1, Math.floor(((i + 1) / n) * peaks.length));
        let m = 0;
        for (let j = a; j < b && j < peaks.length; j++) if (peaks[j] > m) m = peaks[j];
        out.push(m);
    }
    return out;
}