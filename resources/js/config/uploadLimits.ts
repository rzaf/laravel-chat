// src/config/uploadLimits.ts
/**
 * Client-side upload limits. They are deliberately high for now.
 * Change a number to tighten a limit, or set it to `null` to switch that limit off.
 * (The backend should still enforce its own limits.)
 */
export const UPLOAD_LIMITS: {
    /** Any file attached to a chat message. */
    maxAttachmentBytes: number | null;
    /** Profile photos (users, groups, channels). */
    maxProfilePhotoBytes: number | null;
    /** Longest side of an image, in pixels (checked before cropping). */
    maxImageDimension: number | null;
} = {
    maxAttachmentBytes: 200 * 1024 * 1024,
    maxProfilePhotoBytes: 50 * 1024 * 1024,
    maxImageDimension: 20000,
};

export function formatBytes(bytes: number): string {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / Math.pow(1024, i);
    return `${value.toFixed(i === 0 || value >= 100 ? 0 : 1)} ${units[i]}`;
}

/** Returns an error message when the file is over `limit`, otherwise `null`. */
export function checkFileSize(file: File, limit: number | null): string | null {
    if (limit == null || file.size <= limit) return null;
    return `“${file.name}” is too large (${formatBytes(file.size)}). The maximum is ${formatBytes(limit)}.`;
}