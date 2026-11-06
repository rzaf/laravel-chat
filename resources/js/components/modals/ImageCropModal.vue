<!-- src/components/modals/ImageCropModal.vue -->
<script lang="ts">
export interface CropAspect {
    id: string;
    label: string;
    /** width / height, or 'original' (keep the image's own ratio), or 'free' (no lock). */
    ratio: number | 'original' | 'free';
}

/** Crop box in the rotated / flipped image, as fractions (0..1) of its size. */
export interface CropRect {
    x: number;
    y: number;
    w: number;
    h: number;
}

/** Everything needed to re-open the cropper exactly where the user left it. */
export interface CropState {
    aspectId: string;
    rotation: number;
    mirror: boolean;
    rect: CropRect;
}

/**
 * True for files that must NOT go through the cropper because cropping would keep just one frame:
 * animated GIF / WebP / APNG, and video files that are mislabelled as images (e.g. WebM).
 * Detection is based on the file's bytes, not on its name or MIME type.
 */
export async function isAnimatedImage(file: File): Promise<boolean> {
    try {
        const buf = new Uint8Array(await file.slice(0, 1024).arrayBuffer());
        const ascii = (from: number, len: number) => String.fromCharCode(...buf.slice(from, from + len));
        const indexOf = (text: string) => {
            for (let i = 0; i + text.length <= buf.length; i++) {
                if (ascii(i, text.length) === text) return i;
            }
            return -1;
        };

        if (ascii(0, 3) === 'GIF') return true;
        if (buf[0] === 0x1a && buf[1] === 0x45 && buf[2] === 0xdf && buf[3] === 0xa3) return true; // WebM / Matroska
        if (ascii(0, 4) === 'RIFF' && ascii(8, 4) === 'WEBP') {
            if (ascii(12, 4) === 'VP8X' && (buf[20] & 0x02) !== 0) return true; // animation flag
            return indexOf('ANIM') !== -1;
        }
        if (buf[0] === 0x89 && ascii(1, 3) === 'PNG') {
            const actl = indexOf('acTL'); // APNG control chunk always comes before the image data
            const idat = indexOf('IDAT');
            return actl !== -1 && (idat === -1 || actl < idat);
        }
        return false;
    } catch {
        return false;
    }
}

export type CropHandle = 'move' | 'n' | 's' | 'e' | 'w' | 'nw' | 'ne' | 'sw' | 'se';

const clampNum = (v: number, min: number, max: number) => Math.min(max, Math.max(min, v));

/**
 * Pure crop-box maths (all values in display px, inside a `dw` x `dh` image).
 * `ratio` = locked width/height or null for free resizing. Returns the new box in px.
 */
export function computeCropBox(
    handle: CropHandle,
    dx: number,
    dy: number,
    start: { l: number; t: number; r: number; b: number },
    dw: number,
    dh: number,
    ratio: number | null,
    minSize: number,
): { l: number; t: number; r: number; b: number } {
    let { l, t, r, b } = start;
    const MIN = Math.min(minSize, dw, dh);

    if (handle === 'move') {
        const w = r - l;
        const h = b - t;
        l = clampNum(l + dx, 0, dw - w);
        t = clampNum(t + dy, 0, dh - h);
        return { l, t, r: l + w, b: t + h };
    }

    if (!ratio) {
        if (handle.includes('w')) l = clampNum(l + dx, 0, r - MIN);
        if (handle.includes('e')) r = clampNum(r + dx, l + MIN, dw);
        if (handle.includes('n')) t = clampNum(t + dy, 0, b - MIN);
        if (handle.includes('s')) b = clampNum(b + dy, t + MIN, dh);
        return { l, t, r, b };
    }

    const minW = Math.max(MIN, MIN * ratio);

    if (handle.length === 2) {
        // Corner with locked ratio: the opposite corner stays put.
        const east = handle.includes('e');
        const south = handle.includes('s');
        const ax = east ? l : r;
        const ay = south ? t : b;
        const px = (east ? r : l) + dx;
        const py = (south ? b : t) + dy;
        const rawW = Math.max(0, east ? px - ax : ax - px);
        const rawH = Math.max(0, south ? py - ay : ay - py);
        let w = Math.max(rawW, rawH * ratio);
        const maxW = Math.min(east ? dw - ax : ax, (south ? dh - ay : ay) * ratio);
        w = clampNum(w, minW, maxW);
        const h = w / ratio;
        l = east ? ax : ax - w;
        t = south ? ay : ay - h;
        return { l, t, r: l + w, b: t + h };
    }

    if (handle === 'e' || handle === 'w') {
        // Left/right edge with locked ratio: grows symmetrically up and down.
        const cy = (t + b) / 2;
        let w = handle === 'e' ? r + dx - l : r - (l + dx);
        const maxW = Math.min(handle === 'e' ? dw - l : r, 2 * Math.min(cy, dh - cy) * ratio);
        w = clampNum(w, minW, maxW);
        const h = w / ratio;
        if (handle === 'e') r = l + w;
        else l = r - w;
        return { l, t: cy - h / 2, r, b: cy + h / 2 };
    }

    // Top/bottom edge with locked ratio: grows symmetrically left and right.
    const cx = (l + r) / 2;
    let h = handle === 's' ? b + dy - t : b - (t + dy);
    const maxH = Math.min(handle === 's' ? dh - t : b, (2 * Math.min(cx, dw - cx)) / ratio);
    h = clampNum(h, Math.max(MIN, MIN / ratio), maxH);
    const w = h * ratio;
    if (handle === 's') b = t + h;
    else t = b - h;
    return { l: cx - w / 2, t, r: cx + w / 2, b };
}
</script>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

/**
 * Generic image cropper (no dependencies).
 * - Open it by passing a `file`; close it by passing `null`.
 * - Drag the box to move it, drag its edges / corners to resize it.
 * - One entry in `aspects` = locked ratio (profile photos). Several = ratio pills (chat images).
 */
const props = withDefaults(defineProps<{
    file: File | null;
    title?: string;
    subtitle?: string;
    confirmText?: string;
    aspects?: CropAspect[];
    shape?: 'rect' | 'circle';
    /** Longest side of the exported image, in px (never upscaled). */
    maxSize?: number;
    /** Reject images whose longest side is bigger than this (null = no limit). */
    maxInputDimension?: number | null;
    busy?: boolean;
    error?: string;
    initialState?: CropState | null;
}>(), {
    title: 'Crop image',
    subtitle: '',
    confirmText: 'Done',
    aspects: () => [{ id: '1:1', label: '1:1', ratio: 1 }],
    shape: 'rect',
    maxSize: 2048,
    maxInputDimension: null,
    busy: false,
    error: '',
    initialState: null,
});

const emit = defineEmits<{
    (e: 'confirm', file: File, state: CropState): void;
    (e: 'cancel'): void;
    (e: 'reject', message: string): void;
}>();

const FRAME_PADDING = 20;
const MIN_BOX_PX = 36;

/** Default compression for exported JPEG / WebP images. */
const EXPORT_QUALITY = 0.85;
/** An untouched image smaller than this is sent as the original file (no re-compression). */
const KEEP_ORIGINAL_BELOW_BYTES = 1.5 * 1024 * 1024;

const HANDLES: { id: CropHandle; cls: string; mark: string }[] = [
    { id: 'n', cls: '-top-2.5 left-5 right-5 h-5 cursor-ns-resize', mark: 'bg-white rounded-full shadow h-[3px] w-6' },
    { id: 's', cls: '-bottom-2.5 left-5 right-5 h-5 cursor-ns-resize', mark: 'bg-white rounded-full shadow h-[3px] w-6' },
    { id: 'w', cls: '-left-2.5 top-5 bottom-5 w-5 cursor-ew-resize', mark: 'bg-white rounded-full shadow w-[3px] h-6' },
    { id: 'e', cls: '-right-2.5 top-5 bottom-5 w-5 cursor-ew-resize', mark: 'bg-white rounded-full shadow w-[3px] h-6' },
    { id: 'nw', cls: '-left-3.5 -top-3.5 size-7 cursor-nwse-resize', mark: 'left-3 top-3 size-4 border-t-[3px] border-l-[3px] border-white' },
    { id: 'ne', cls: '-right-3.5 -top-3.5 size-7 cursor-nesw-resize', mark: 'right-3 top-3 size-4 border-t-[3px] border-r-[3px] border-white' },
    { id: 'sw', cls: '-left-3.5 -bottom-3.5 size-7 cursor-nesw-resize', mark: 'left-3 bottom-3 size-4 border-b-[3px] border-l-[3px] border-white' },
    { id: 'se', cls: '-right-3.5 -bottom-3.5 size-7 cursor-nwse-resize', mark: 'right-3 bottom-3 size-4 border-b-[3px] border-r-[3px] border-white' },
];

const dialogRef = ref<HTMLDialogElement | null>(null);
const stageRef = ref<HTMLElement | null>(null);

const imageUrl = ref('');
const imageEl = shallowRef<HTMLImageElement | null>(null);
const ready = ref(false);
const loadFailed = ref(false);
const working = ref(false);
const localError = ref('');

const naturalW = ref(0);
const naturalH = ref(0);
const stageW = ref(0);
const stageH = ref(0);

const aspectId = ref('');
const rotation = ref(0);
const mirror = ref(false);
const rect = ref<CropRect>({ x: 0, y: 0, w: 1, h: 1 });

/** View zoom (1 = whole image fits the window) and pan, in px. Zooming never changes the crop itself. */
const zoom = ref(1);
const panX = ref(0);
const panY = ref(0);

const clamp = clampNum;

/* ---------------------------------------------------------------- geometry */

const quarterTurned = computed(() => rotation.value % 180 !== 0);
/** Size of the image after rotation (flipping doesn't change it). */
const rw = computed(() => (quarterTurned.value ? naturalH.value : naturalW.value));
const rh = computed(() => (quarterTurned.value ? naturalW.value : naturalH.value));

const currentAspect = computed(() => props.aspects.find((a) => a.id === aspectId.value) ?? props.aspects[0]);

/** Width / height the crop box is locked to, or null when free. */
const lockedRatio = computed<number | null>(() => {
    const ratio = currentAspect.value?.ratio;
    if (ratio === undefined || ratio === 'free') return null;
    if (ratio === 'original') return rh.value ? rw.value / rh.value : 1;
    return ratio;
});

/** Zoom needed to see the image at 2x its real pixels (capped), so big photos can be inspected closely. */
const baseScale = computed(() => {
    const aw = Math.max(40, stageW.value - FRAME_PADDING * 2);
    const ah = Math.max(40, stageH.value - FRAME_PADDING * 2);
    return rw.value && rh.value ? Math.min(aw / rw.value, ah / rh.value) : 1;
});
const maxZoom = computed(() => clamp(2 / baseScale.value, 4, 40));
const zoomClamped = computed(() => clamp(zoom.value, 1, maxZoom.value));

/** Where the (zoomed) image sits inside the stage, in px. */
const display = computed(() => {
    const d = baseScale.value * zoomClamped.value;
    const w = rw.value * d;
    const h = rh.value * d;
const overflowX = Math.max(0, (w - stageW.value) / 2);
const overflowY = Math.max(0, (h - stageH.value) / 2);
// If the image is smaller than the stage, lock it to 0 (centered). 
// If larger, allow panning up to the overflow edge.
const maxPanX = overflowX;
const maxPanY = overflowY;

    const px = clamp(panX.value, -maxPanX, maxPanX);
    const py = clamp(panY.value, -maxPanY, maxPanY);
    return { d, w, h, px, py, maxPanX, maxPanY, left: (stageW.value - w) / 2 + px, top: (stageH.value - h) / 2 + py };
});

const imageStyle = computed(() => ({
    width: `${naturalW.value}px`,
    height: `${naturalH.value}px`,
    marginLeft: `${-naturalW.value / 2}px`,
    marginTop: `${-naturalH.value / 2}px`,
    transform: `translate(${display.value.px}px, ${display.value.py}px) rotate(${rotation.value}deg) scaleX(${mirror.value ? -1 : 1}) scale(${display.value.d})`,
}));

const boxStyle = computed(() => {
    const { left, top, w, h } = display.value;
    return {
        left: `${left + rect.value.x * w}px`,
        top: `${top + rect.value.y * h}px`,
        width: `${rect.value.w * w}px`,
        height: `${rect.value.h * h}px`,
    };
});

/** Largest box with the given width/height ratio that fits the image, centred on (cx, cy) (fractions). */
function fitRect(ratio: number, cx: number, cy: number): CropRect {
    const iw = rw.value || 1;
    const ih = rh.value || 1;
    let wPx: number;
    let hPx: number;
    if (iw / ih > ratio) {
        hPx = ih;
        wPx = ih * ratio;
    } else {
        wPx = iw;
        hPx = iw / ratio;
    }
    const w = wPx / iw;
    const h = hPx / ih;
    return { x: clamp(cx - w / 2, 0, 1 - w), y: clamp(cy - h / 2, 0, 1 - h), w, h };
}

const fullRect = (): CropRect => ({ x: 0, y: 0, w: 1, h: 1 });

function defaultRect(): CropRect {
    return lockedRatio.value ? fitRect(lockedRatio.value, 0.5, 0.5) : fullRect();
}

const outputSize = computed(() => {
    const sw = rect.value.w * rw.value;
    const sh = rect.value.h * rh.value;
    const k = Math.min(1, props.maxSize / Math.max(sw, sh, 1));
    return { w: Math.max(1, Math.round(sw * k)), h: Math.max(1, Math.round(sh * k)) };
});

/* ------------------------------------------------------------- interaction */

let drag: { handle: CropHandle; startX: number; startY: number; box: { l: number; t: number; r: number; b: number } } | null = null;

/* ---- zoom & pan of the view: wheel, slider, pinch (two fingers) and drag on empty space ---- */

const stagePointers = new Map<number, { x: number; y: number }>();
let pinch: { dist: number; zoom: number; midX: number; midY: number } | null = null;
let panning: { id: number; startX: number; startY: number; px: number; py: number } | null = null;

const zoomSlider = computed(() => (maxZoom.value > 1 ? Math.log(zoomClamped.value) / Math.log(maxZoom.value) : 0));

function toStage(clientX: number, clientY: number): { x: number; y: number } {
    const r = stageRef.value?.getBoundingClientRect();
    return { x: clientX - (r?.left ?? 0), y: clientY - (r?.top ?? 0) };
}

function setPan(x: number, y: number): void {
    panX.value = clamp(x, -display.value.maxPanX, display.value.maxPanX);
    panY.value = clamp(y, -display.value.maxPanY, display.value.maxPanY);
}

/** Zoom while keeping the image point under (sx, sy) (stage px) where it is. */
function zoomTo(next: number, sx: number, sy: number): void {
    const old = zoomClamped.value;
    const target = clamp(next, 1, maxZoom.value);
    if (target === old) return;
    const f = target / old;
    const cx = stageW.value / 2 + display.value.px;
    const cy = stageH.value / 2 + display.value.py;
    zoom.value = target;
    panX.value = sx - (sx - cx) * f - stageW.value / 2;
    panY.value = sy - (sy - cy) * f - stageH.value / 2;
}

function zoomCenter(next: number): void {
    zoomTo(next, stageW.value / 2, stageH.value / 2);
}

function onZoomSlider(e: Event): void {
    zoomCenter(Math.exp(Number((e.target as HTMLInputElement).value) * Math.log(maxZoom.value)));
}

function onWheel(e: WheelEvent): void {
    if (!ready.value) return;
    const p = toStage(e.clientX, e.clientY);
    zoomTo(zoomClamped.value * Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.002)), p.x, p.y);
}

function pointerPair() {
    const [a, b] = Array.from(stagePointers.values());
    return { a, b, midX: (a.x + b.x) / 2, midY: (a.y + b.y) / 2, dist: Math.hypot(a.x - b.x, a.y - b.y) || 1 };
}

// Registered in the capture phase, so they see every pointer even while the crop box has captured one.
function onStageDown(e: PointerEvent): void {
    if (!ready.value) return;
    stagePointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (stagePointers.size === 2) {
        const p = pointerPair();
        drag = null; // a second finger turns the gesture into a pinch
        panning = null;
        pinch = { dist: p.dist, zoom: zoomClamped.value, midX: p.midX, midY: p.midY };
    } else if (stagePointers.size === 1 && e.target === e.currentTarget && zoomClamped.value > 1) {
        (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
        panning = { id: e.pointerId, startX: e.clientX, startY: e.clientY, px: display.value.px, py: display.value.py };
    }
}

function onStageMove(e: PointerEvent): void {
    if (!stagePointers.has(e.pointerId)) return;
    stagePointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pinch && stagePointers.size >= 2) {
        const p = pointerPair();
        setPan(display.value.px + (p.midX - pinch.midX), display.value.py + (p.midY - pinch.midY));
        pinch.midX = p.midX;
        pinch.midY = p.midY;
        const at = toStage(p.midX, p.midY);
        zoomTo(pinch.zoom * (p.dist / pinch.dist), at.x, at.y);
    } else if (panning && e.pointerId === panning.id) {
        setPan(panning.px + (e.clientX - panning.startX), panning.py + (e.clientY - panning.startY));
    }
}

function onStageUp(e: PointerEvent): void {
    stagePointers.delete(e.pointerId);
    if (stagePointers.size < 2) pinch = null;
    if (panning?.id === e.pointerId) panning = null;
}

function onStageDblClick(e: MouseEvent): void {
    if (!ready.value) return;
    const p = toStage(e.clientX, e.clientY);
    if (zoomClamped.value > 1.1) {
        zoomCenter(1); // Reset to fit
    } else {
        zoomTo(2.5, p.x, p.y); // Zoom into where they clicked
    }
}

function onBoxPointerDown(e: PointerEvent): void {
    if (!ready.value || props.busy || stagePointers.size > 1) return;
    const handle = ((e.target as HTMLElement).dataset.h as CropHandle | undefined) ?? 'move';
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    const { w, h } = display.value;
    const r = rect.value;
    drag = {
        handle,
        startX: e.clientX,
        startY: e.clientY,
        box: { l: r.x * w, t: r.y * h, r: (r.x + r.w) * w, b: (r.y + r.h) * h },
    };
    e.preventDefault();
}

function onBoxPointerMove(e: PointerEvent): void {
    if (!drag) return;
    const { w, h } = display.value;
    const box = computeCropBox(drag.handle, e.clientX - drag.startX, e.clientY - drag.startY, drag.box, w, h, lockedRatio.value, MIN_BOX_PX);
    rect.value = {
        x: clamp(box.l / w, 0, 1),
        y: clamp(box.t / h, 0, 1),
        w: clamp((box.r - box.l) / w, 0, 1),
        h: clamp((box.b - box.t) / h, 0, 1),
    };
}

function onBoxPointerUp(): void {
    drag = null;
}

function selectAspect(id: string): void {
    aspectId.value = id;
    const a = props.aspects.find((x) => x.id === id);
    if (!a || a.ratio === 'free' || !lockedRatio.value) return;
    const r = rect.value;
    rect.value = fitRect(lockedRatio.value, r.x + r.w / 2, r.y + r.h / 2);
}

function rotate(): void {
    const r = rect.value;
    rotation.value = (rotation.value + 90) % 360;
    rect.value = { x: 1 - r.y - r.h, y: r.x, w: r.h, h: r.w };
    // A fixed numeric ratio (e.g. 4:3) stays 4:3, so re-fit; "Original" and "Free" follow the rotation.
    if (typeof currentAspect.value?.ratio === 'number') {
        rect.value = fitRect(currentAspect.value.ratio, rect.value.x + rect.value.w / 2, rect.value.y + rect.value.h / 2);
    }
}

function flipHorizontal(): void {
    rotation.value = (360 - rotation.value) % 360;
    mirror.value = !mirror.value;
    rect.value = { ...rect.value, x: 1 - rect.value.x - rect.value.w };
}

function flipVertical(): void {
    rotation.value = (540 - rotation.value) % 360;
    mirror.value = !mirror.value;
    rect.value = { ...rect.value, y: 1 - rect.value.y - rect.value.h };
}

function resetState(): void {
    aspectId.value = props.aspects[0]?.id ?? '';
    rotation.value = 0;
    mirror.value = false;
    zoom.value = 1;
    panX.value = 0;
    panY.value = 0;
    rect.value = defaultRect();
}

function applyInitialState(): void {
    resetState();
    const s = props.initialState;
    if (!s) return;
    if (props.aspects.some((a) => a.id === s.aspectId)) aspectId.value = s.aspectId;
    rotation.value = [0, 90, 180, 270].includes(s.rotation) ? s.rotation : 0;
    mirror.value = !!s.mirror;
    const w = clamp(s.rect.w, 0.01, 1);
    const h = clamp(s.rect.h, 0.01, 1);
    rect.value = { x: clamp(s.rect.x, 0, 1 - w), y: clamp(s.rect.y, 0, 1 - h), w, h };
}

/* ------------------------------------------------------------- file loading */

function releaseImage(): void {
    if (imageUrl.value) URL.revokeObjectURL(imageUrl.value);
    imageUrl.value = '';
    imageEl.value = null;
    ready.value = false;
    drag = null;
    pinch = null;
    panning = null;
    stagePointers.clear();
}

watch(
    () => props.file,
    async (file) => {
        releaseImage();
        localError.value = '';
        loadFailed.value = false;

        const dialog = dialogRef.value;
        if (!file) {
            if (dialog?.open) dialog.close();
            return;
        }

        // A cropped animation would be flattened to a single frame, so these are not accepted here.
        if (await isAnimatedImage(file)) {
            if (props.file === file) emit('reject', 'Animated images (GIF, animated WebP…) can’t be cropped, because only a single frame would be kept.');
            return;
        }
        if (props.file !== file) return;

        imageUrl.value = URL.createObjectURL(file);
        await nextTick();
        if (dialogRef.value && !dialogRef.value.open) dialogRef.value.showModal();

        const el = new Image();
        el.src = imageUrl.value;
        try {
            await el.decode(); // also applies EXIF orientation
        } catch {
            if (props.file === file) loadFailed.value = true;
            return;
        }
        if (props.file !== file) return;

        const longest = Math.max(el.naturalWidth, el.naturalHeight);
        if (props.maxInputDimension != null && longest > props.maxInputDimension) {
            emit('reject', `This image is too large (${el.naturalWidth} × ${el.naturalHeight}px). The maximum is ${props.maxInputDimension}px on the longest side.`);
            return;
        }

        naturalW.value = el.naturalWidth;
        naturalH.value = el.naturalHeight;
        imageEl.value = el;
        applyInitialState();
        ready.value = true;
    },
    { immediate: true },
);

let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
    if (!stageRef.value) return;
    resizeObserver = new ResizeObserver(([entry]) => {
        stageW.value = entry.contentRect.width;
        stageH.value = entry.contentRect.height;
    });
    resizeObserver.observe(stageRef.value);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    releaseImage();
});

/* ------------------------------------------------------------------ output */

function outputName(original: string, mime: string): string {
    const ext = mime === 'image/png' ? 'png' : mime === 'image/webp' ? 'webp' : 'jpg';
    const base = original.replace(/\.[^./\\]+$/, '') || 'image';
    return `${base}.${ext}`;
}

function toBlob(canvas: HTMLCanvasElement, type: string, q: number): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, q));
}

async function confirm(): Promise<void> {
    const source = props.file;
    const img = imageEl.value;
    if (!source || !img || !ready.value || props.busy || working.value) return;

    const state: CropState = {
        aspectId: aspectId.value,
        rotation: rotation.value,
        mirror: mirror.value,
        rect: { ...rect.value },
    };

    // Crop size in the rotated image's own pixels.
    const sw = rect.value.w * rw.value;
    const sh = rect.value.h * rh.value;
    const maxSide = props.maxSize;

    // Nothing was changed by the user (whole image, no rotation / flip).
    const untouched = rotation.value === 0 && !mirror.value && sw >= naturalW.value - 1 && sh >= naturalH.value - 1;

    // Untouched and already small: send the original file as it is.
    if (untouched && Math.max(naturalW.value, naturalH.value) <= maxSide && source.size <= KEEP_ORIGINAL_BELOW_BYTES) {
        emit('confirm', source, state);
        return;
    }

    working.value = true;
    localError.value = '';
    try {
        const k = Math.min(1, maxSide / Math.max(sw, sh));
        const ow = Math.max(1, Math.round(sw * k));
        const oh = Math.max(1, Math.round(sh * k));

        const canvas = document.createElement('canvas');
        canvas.width = ow;
        canvas.height = oh;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas not supported');

        const mime = source.type === 'image/png' ? 'image/png' : source.type === 'image/webp' ? 'image/webp' : 'image/jpeg';
        if (mime === 'image/jpeg') {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, ow, oh);
        }
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';

        // Crop centre relative to the image centre, in rotated-image px.
        const centerX = (rect.value.x + rect.value.w / 2 - 0.5) * rw.value;
        const centerY = (rect.value.y + rect.value.h / 2 - 0.5) * rh.value;

        ctx.translate(ow / 2, oh / 2);
        ctx.scale(ow / sw, oh / sh);
        ctx.translate(-centerX, -centerY);
        ctx.rotate((rotation.value * Math.PI) / 180);
        ctx.scale(mirror.value ? -1 : 1, 1);
        ctx.drawImage(img, -naturalW.value / 2, -naturalH.value / 2);

        const blob = await toBlob(canvas, mime, EXPORT_QUALITY);
        if (!blob) throw new Error('Could not encode image');

        // Re-encoding must never make an untouched image bigger (e.g. screenshots saved as PNG).
        if (untouched && blob.size >= source.size) {
            emit('confirm', source, state);
            return;
        }

        emit('confirm', new File([blob], outputName(source.name, mime), { type: mime, lastModified: Date.now() }), state);
    } catch (error) {
        console.error('Failed to crop image', error);
        localError.value = 'Could not crop this image. Try another one.';
    } finally {
        working.value = false;
    }
}

function requestCancel(): void {
    if (props.busy || working.value) return;
    emit('cancel');
}

function onEnter(e: KeyboardEvent): void {
    if ((e.target as HTMLElement).tagName === 'BUTTON') return;
    e.preventDefault();
    void confirm();
}

const shownError = computed(() => localError.value || props.error);
const isBusy = computed(() => props.busy || working.value);
</script>

<template>
    <!-- Native <dialog>: rendered in the top layer, so it also sits above the Profile modal. -->
    <dialog ref="dialogRef" aria-label="Crop image"
        class="m-auto w-[min(94vw,520px)] max-h-[96vh] overflow-hidden rounded-2xl bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-black/70 dark:bg-gray-800 dark:text-gray-100"
        @cancel.prevent="requestCancel" @keydown.esc.stop @keydown.enter="onEnter">
        <div class="flex flex-col">
            <!-- Header -->
            <div
                class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold truncate">{{ title }}</h2>
                    <p v-if="subtitle" class="text-xs text-gray-500 truncate dark:text-gray-400">{{ subtitle }}</p>
                </div>
                <button type="button" aria-label="Cancel" :disabled="isBusy"
                    class="grid p-2 text-gray-500 transition-colors rounded-full cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                    @click="requestCancel">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Stage -->
            <div ref="stageRef" class="relative h-[min(58vh,440px)] overflow-hidden bg-black select-none touch-none"
                :class="zoomClamped > 1 ? 'cursor-grab active:cursor-grabbing' : ''" @pointerdown.capture="onStageDown"
                @pointermove.capture="onStageMove" @pointerup.capture="onStageUp" @pointercancel.capture="onStageUp"
                @wheel.prevent="onWheel" @dblclick="onStageDblClick">
                <img v-if="ready" :src="imageUrl" alt="" draggable="false"
                    class="absolute left-1/2 top-1/2 max-w-none pointer-events-none origin-center" :style="imageStyle"
                    @dragstart.prevent />

                <!-- Crop box: the huge box-shadow dims everything outside it -->
                <div v-if="ready"
                    class="absolute border border-white/90 cursor-move touch-none shadow-[0_0_0_9999px_rgba(0,0,0,0.6)]"
                    :style="boxStyle" @pointerdown="onBoxPointerDown" @pointermove="onBoxPointerMove"
                    @pointerup="onBoxPointerUp" @pointercancel="onBoxPointerUp">
                    <!-- Circle preview (profile photos) -->
                    <div v-if="shape === 'circle'" class="absolute inset-0 overflow-hidden pointer-events-none">
                        <div
                            class="absolute inset-0 border border-white/70 rounded-full shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" />
                    </div>
                    <!-- Rule-of-thirds grid -->
                    <template v-else>
                        <span class="absolute inset-y-0 left-1/3 w-px bg-white/30 pointer-events-none" />
                        <span class="absolute inset-y-0 left-2/3 w-px bg-white/30 pointer-events-none" />
                        <span class="absolute inset-x-0 top-1/3 h-px bg-white/30 pointer-events-none" />
                        <span class="absolute inset-x-0 top-2/3 h-px bg-white/30 pointer-events-none" />
                    </template>

                    <span v-for="h in HANDLES" :key="h.id" :data-h="h.id"
                        class="absolute z-10 flex items-center justify-center touch-none" :class="h.cls">
                        <span class="absolute pointer-events-none" :class="h.mark" />
                    </span>
                </div>

                <div v-if="!ready"
                    class="absolute inset-0 flex items-center justify-center px-6 text-sm text-center text-gray-300">
                    {{ loadFailed ? 'This image could not be opened.' : 'Loading…' }}
                </div>
            </div>

            <!-- Controls -->
            <div class="flex flex-col gap-3 px-4 pt-3">
                <div v-if="aspects.length > 1" class="flex flex-wrap gap-1.5" role="radiogroup"
                    aria-label="Aspect ratio">
                    <button v-for="a in aspects" :key="a.id" type="button" role="radio"
                        :aria-checked="a.id === aspectId" :disabled="!ready || isBusy"
                        class="px-3 py-1 text-xs font-medium transition-colors rounded-full cursor-pointer disabled:opacity-40"
                        :class="a.id === aspectId ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'"
                        @click="selectAspect(a.id)">{{ a.label }}</button>
                </div>

                <!-- Zoom (wheel, pinch or slider) -->
                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                    <button type="button" aria-label="Zoom out" :disabled="!ready || isBusy"
                        class="grid p-1 rounded-full cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                        @click="zoomCenter(zoomClamped / 1.5)">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35M8 11h6" />
                        </svg>
                    </button>
                    <input type="range" min="0" max="1" step="0.001" :value="zoomSlider" :disabled="!ready || isBusy"
                        aria-label="Zoom" class="flex-1 cursor-pointer accent-blue-600" @input="onZoomSlider" />
                    <button type="button" aria-label="Zoom in" :disabled="!ready || isBusy"
                        class="grid p-1 rounded-full cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                        @click="zoomCenter(zoomClamped * 1.5)">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35M8 11h6M11 8v6" />
                        </svg>
                    </button>
                </div>

                <div class="flex items-center gap-1 text-gray-600 dark:text-gray-300">
                    <button type="button" aria-label="Rotate 90 degrees" title="Rotate" :disabled="!ready || isBusy"
                        class="grid p-2 transition-colors rounded-full cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                        @click="rotate">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8" />
                            <path d="M21 3v5h-5" />
                        </svg>
                    </button>
                    <button type="button" aria-label="Flip horizontally" title="Flip horizontally"
                        :disabled="!ready || isBusy"
                        class="grid p-2 transition-colors rounded-full cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                        @click="flipHorizontal">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3 7 5 5-5 5V7" />
                            <path d="m21 7-5 5 5 5V7" />
                            <path d="M12 20v2M12 14v2M12 8v2M12 2v2" />
                        </svg>
                    </button>
                    <button type="button" aria-label="Flip vertically" title="Flip vertically"
                        :disabled="!ready || isBusy"
                        class="grid p-2 transition-colors rounded-full cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                        @click="flipVertical">
                        <svg viewBox="0 0 24 24" class="size-5 rotate-90" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3 7 5 5-5 5V7" />
                            <path d="m21 7-5 5 5 5V7" />
                            <path d="M12 20v2M12 14v2M12 8v2M12 2v2" />
                        </svg>
                    </button>
                    <button type="button" :disabled="!ready || isBusy"
                        class="px-2 py-1 ml-1 text-xs font-medium rounded-lg cursor-pointer hover:bg-black/5 dark:hover:bg-white/10 disabled:opacity-40"
                        @click="resetState">Reset</button>

                    <span v-if="ready" class="ml-auto text-xs text-gray-500 tabular-nums dark:text-gray-400">
                        {{ outputSize.w }} × {{ outputSize.h }} px
                    </span>
                </div>

                <p v-if="shownError" role="alert" class="text-xs text-red-500">{{ shownError }}</p>
            </div>

            <!-- Footer -->
            <div class="flex justify-end gap-2 px-4 py-3">
                <button type="button" :disabled="isBusy"
                    class="px-4 py-2 text-sm font-medium text-gray-600 rounded-full cursor-pointer dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40"
                    @click="requestCancel">Cancel</button>
                <button type="button" :disabled="!ready || isBusy"
                    class="px-5 py-2 text-sm font-medium text-white bg-blue-600 rounded-full cursor-pointer hover:bg-blue-700 disabled:opacity-50 shadow-sm"
                    @click="confirm">{{ isBusy ? 'Please wait…' : confirmText }}</button>
            </div>
        </div>
    </dialog>
</template>