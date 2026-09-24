import { expect, type Locator, type Page } from '@playwright/test';
import { inflateSync } from 'node:zlib';

/** Channels 0 to 255, alpha 0 to 1. */
export type Rgba = [number, number, number, number];

/** Text ink measured against the first opaque ancestor background, with any translucent layers above it composited on. */
export type Ink = {
    color: Rgba;
    ground: Rgba;
    ratio: number;
    large: boolean;
    text: string;
    where: string;
};

export const VIEWPORTS = [
    { width: 1440, height: 900 },
    { width: 390, height: 844 },
] as const;

/**
 * The measuring code, installed into every page before its own scripts run so
 * each evaluate reaches the same functions. Self-contained: Playwright ships it
 * as source.
 */
export function installInk(): void {
    const parse = (value: string): Rgba | null => {
        const v = value.trim();
        if (v === 'transparent') return [0, 0, 0, 0];
        const alpha = (a: string | undefined): number => {
            if (a === undefined || a === '') return 1;
            return a.endsWith('%') ? parseFloat(a) / 100 : parseFloat(a);
        };
        let m = /^rgba?\(([^)]*)\)$/i.exec(v);
        if (m) {
            const p = m[1].split(/[\s,/]+/).filter(Boolean);
            return [parseFloat(p[0]), parseFloat(p[1]), parseFloat(p[2]), alpha(p[3])];
        }
        m = /^color\(srgb\s+([^)]*)\)$/i.exec(v);
        if (m) {
            const [channels, a] = m[1].split('/');
            const p = channels.trim().split(/\s+/).map((c) => parseFloat(c) * 255);
            return [p[0], p[1], p[2], alpha(a?.trim())];
        }
        return null;
    };

    const over = (top: Rgba, bottom: Rgba): Rgba => [
        top[3] * top[0] + (1 - top[3]) * bottom[0],
        top[3] * top[1] + (1 - top[3]) * bottom[1],
        top[3] * top[2] + (1 - top[3]) * bottom[2],
        1,
    ];

    const luminance = (c: Rgba): number => {
        const [r, g, b] = c.slice(0, 3).map((x) => {
            const s = Math.max(0, Math.min(255, x)) / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };

    const ratio = (a: Rgba, b: Rgba): number => {
        const l1 = luminance(a);
        const l2 = luminance(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    };

    const groundOf = (el: Element): Rgba => {
        const layers: Rgba[] = [];
        for (let n: Element | null = el; n; n = n.parentElement) {
            const bg = parse(getComputedStyle(n).backgroundColor);
            if (bg && bg[3] > 0) {
                layers.push(bg);
                if (bg[3] >= 1) break;
            }
        }
        let ground: Rgba = [255, 255, 255, 1];
        if (layers.length > 0 && layers[layers.length - 1][3] >= 1) {
            ground = layers.pop() as Rgba;
        }
        for (let i = layers.length - 1; i >= 0; i--) {
            ground = over(layers[i], ground);
        }
        return ground;
    };

    const where = (el: Element): string => {
        const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(Boolean).join('.') : '';
        return el.tagName.toLowerCase() + (cls ? '.' + cls : '');
    };

    const measure = (el: Element, colorProperty = 'color'): Ink => {
        const cs = getComputedStyle(el);
        const raw = parse(cs.getPropertyValue(colorProperty));
        if (! raw) throw new Error(`Unreadable ${colorProperty} "${cs.getPropertyValue(colorProperty)}" on ${where(el)}`);
        const ground = groundOf(colorProperty === 'color' ? el : (el.parentElement ?? el));
        const size = parseFloat(cs.fontSize);
        const weight = parseInt(cs.fontWeight, 10) || 400;
        return {
            color: raw,
            ground,
            ratio: ratio(over(raw, ground), ground),
            large: size >= 24 || (size >= 18.66 && weight >= 700),
            text: (el.textContent ?? '').trim().slice(0, 60),
            where: where(el),
        };
    };

    const visible = (el: Element, node: Text): boolean => {
        if (! el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) return false;
        const range = document.createRange();
        range.selectNodeContents(node);
        const box = range.getBoundingClientRect();
        if (box.width < 1 || box.height < 1 || box.right < 0) return false;
        for (let n: Element | null = el; n; n = n.parentElement) {
            const s = getComputedStyle(n);
            if (s.position === 'absolute' && s.clip !== 'auto') return false;
            if (s.clipPath !== 'none') return false;
        }
        return true;
    };

    /** Every visible text run under the roots, and those below the WCAG bar for their size. */
    const sweep = (selector: string): { count: number; failures: Ink[] } => {
        const seen = new Set<Node>();
        const failures: Ink[] = [];
        let count = 0;
        for (const root of Array.from(document.querySelectorAll(selector))) {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            for (let node = walker.nextNode() as Text | null; node; node = walker.nextNode() as Text | null) {
                if (seen.has(node)) continue;
                seen.add(node);
                const el = node.parentElement;
                if (! el || (node.textContent ?? '').trim() === '') continue;
                if (el.closest('script, style, noscript, template, select, option, textarea, :disabled')) continue;
                // Initials repeat the name beside them, on a hue taken from the name rather than the brand.
                if (el.closest('.gratora-avatar')) continue;
                if (! visible(el, node)) continue;
                count++;
                const ink = measure(el);
                if (ink.ratio < (ink.large ? 3 : 4.5)) {
                    failures.push({ ...ink, text: (node.textContent ?? '').trim().slice(0, 60) });
                }
            }
        }
        return { count, failures };
    };

    (window as unknown as { __gratoraInk: unknown }).__gratoraInk = { measure, groundOf, ratio, sweep };
}

type InkApi = {
    measure: (el: Element, colorProperty?: string) => Ink;
    groundOf: (el: Element) => Rgba;
    ratio: (a: Rgba, b: Rgba) => number;
    sweep: (selector: string) => { count: number; failures: Ink[] };
};

export async function inkOf(locator: Locator, colorProperty = 'color'): Promise<Ink> {
    return locator.evaluate(
        (el, prop) => (window as unknown as { __gratoraInk: InkApi }).__gratoraInk.measure(el, prop),
        colorProperty,
    );
}

export async function inksOf(locator: Locator): Promise<Ink[]> {
    return locator.evaluateAll((els) => els.map((el) => (window as unknown as { __gratoraInk: InkApi }).__gratoraInk.measure(el)));
}

export async function groundOf(locator: Locator): Promise<Rgba> {
    return locator.evaluate((el) => (window as unknown as { __gratoraInk: InkApi }).__gratoraInk.groundOf(el));
}

/** Text runs under the Gratora surfaces of a page that read below the bar. */
export async function sweep(page: Page, selector: string): Promise<{ count: number; failures: Ink[] }> {
    return page.evaluate((sel) => (window as unknown as { __gratoraInk: InkApi }).__gratoraInk.sweep(sel), selector);
}

export function contrast(a: Rgba, b: Rgba): number {
    const lum = (c: Rgba): number => {
        const [r, g, bl] = c.slice(0, 3).map((x) => {
            const s = Math.max(0, Math.min(255, x)) / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
    };
    const l1 = lum(a);
    const l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

export function parseRgb(value: string): Rgba {
    const m = /^rgba?\(([^)]*)\)$/i.exec(value.trim());
    if (! m) throw new Error(`Not an rgb() colour: ${value}`);
    const p = m[1].split(/[\s,/]+/).filter(Boolean).map(parseFloat);
    return [p[0], p[1], p[2], p[3] ?? 1];
}

const show = (c: Rgba): string => `rgba(${c.map((x, i) => (i < 3 ? Math.round(x) : Math.round(x * 100) / 100)).join(', ')})`;

/** Channels match once rounded to whole numbers, alpha to two places. */
export function expectRgb(actual: Rgba, expected: string, what: string): void {
    const want = parseRgb(expected);
    const same = [0, 1, 2].every((i) => Math.round(actual[i]) === want[i]) && Math.abs(actual[3] - want[3]) < 0.01;
    expect(same, `${what}: ${show(actual)}, wanted ${expected}`).toBe(true);
}

export function describeInk(ink: Ink): string {
    return `${ink.where} "${ink.text}" ${show(ink.color)} on ${show(ink.ground)} = ${ink.ratio.toFixed(2)}:1`;
}

export function expectReadable(ink: Ink, what: string): void {
    expect(ink.ratio, `${what}: ${describeInk(ink)}`).toBeGreaterThanOrEqual(ink.large ? 3 : 4.5);
}

export async function waitForForms(page: Page): Promise<void> {
    await page.waitForLoadState('load');
    const forms = page.locator('form.gratora-donation-form');
    const n = await forms.count();
    for (let i = 0; i < n; i++) {
        await expect(forms.nth(i)).toHaveAttribute('data-gratora-ready', /.*/, { timeout: 15_000 });
    }
}

/** RGBA pixels of a PNG as Playwright writes it: 8 bits, RGB or RGBA, not interlaced. */
export function decodePng(png: Buffer): { width: number; height: number; pixel: (x: number, y: number) => Rgba } {
    let pos = 8;
    let width = 0;
    let height = 0;
    let channels = 0;
    const idat: Buffer[] = [];
    while (pos < png.length) {
        const length = png.readUInt32BE(pos);
        const type = png.toString('latin1', pos + 4, pos + 8);
        const body = png.subarray(pos + 8, pos + 8 + length);
        if (type === 'IHDR') {
            width = body.readUInt32BE(0);
            height = body.readUInt32BE(4);
            if (body[8] !== 8 || body[12] !== 0) throw new Error('Only 8-bit, non-interlaced PNGs are read.');
            channels = body[9] === 6 ? 4 : body[9] === 2 ? 3 : 0;
            if (channels === 0) throw new Error(`PNG colour type ${body[9]} is not read.`);
        } else if (type === 'IDAT') {
            idat.push(body);
        }
        pos += 12 + length;
    }

    const raw = inflateSync(Buffer.concat(idat));
    const stride = width * channels;
    const out = Buffer.alloc(height * stride);
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)];
        const line = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
        for (let x = 0; x < stride; x++) {
            const left = x >= channels ? out[y * stride + x - channels] : 0;
            const up = y > 0 ? out[(y - 1) * stride + x] : 0;
            const upLeft = y > 0 && x >= channels ? out[(y - 1) * stride + x - channels] : 0;
            let v = line[x];
            if (filter === 1) v += left;
            else if (filter === 2) v += up;
            else if (filter === 3) v += Math.floor((left + up) / 2);
            else if (filter === 4) {
                const p = left + up - upLeft;
                const pa = Math.abs(p - left);
                const pb = Math.abs(p - up);
                const pc = Math.abs(p - upLeft);
                v += pa <= pb && pa <= pc ? left : pb <= pc ? up : upLeft;
            }
            out[y * stride + x] = v & 0xff;
        }
    }

    return {
        width,
        height,
        pixel: (x, y) => {
            const i = y * stride + x * channels;
            return [out[i], out[i + 1], out[i + 2], channels === 4 ? out[i + 3] / 255 : 1];
        },
    };
}
