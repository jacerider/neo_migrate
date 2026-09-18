import { readFileSync, writeFileSync } from 'node:fs';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

export function readPng(path) {
  return PNG.sync.read(readFileSync(path));
}

export function writePng(path, png) {
  writeFileSync(path, PNG.sync.write(png));
}

/**
 * A copy of a rectangle of an image; parts outside it are left transparent.
 */
export function crop(png, x, y, width, height) {
  const out = new PNG({ width: Math.max(1, width), height: Math.max(1, height) });
  PNG.bitblt(png, out, clampX(png, x), clampY(png, y), clampW(png, x, width), clampH(png, y, height), 0, 0);
  return out;
}

/**
 * The image placed on a larger canvas filled with magenta, so that size
 * differences count as difference rather than being cropped away.
 */
export function pad(png, width, height) {
  if (png.width === width && png.height === height) {
    return png;
  }
  const out = new PNG({ width, height });
  for (let i = 0; i < out.data.length; i += 4) {
    out.data[i] = 255;
    out.data[i + 1] = 0;
    out.data[i + 2] = 255;
    out.data[i + 3] = 255;
  }
  PNG.bitblt(png, out, 0, 0, png.width, png.height, 0, 0);
  return out;
}

/**
 * Compares two images of any size.
 *
 * @returns {{percent: number, pixels: number, diff: PNG, a: PNG, b: PNG}}
 */
export function diff(a, b) {
  const width = Math.max(a.width, b.width);
  const height = Math.max(a.height, b.height);
  const pa = pad(a, width, height);
  const pb = pad(b, width, height);
  const out = new PNG({ width, height });
  const pixels = pixelmatch(pa.data, pb.data, out.data, width, height, { threshold: 0.1, includeAA: false });
  return { percent: round((pixels / (width * height)) * 100), pixels, diff: out, a: pa, b: pb };
}

function round(value) {
  return Math.round(value * 100) / 100;
}

function clampX(png, x) {
  return Math.min(Math.max(0, Math.round(x)), png.width - 1);
}

function clampY(png, y) {
  return Math.min(Math.max(0, Math.round(y)), png.height - 1);
}

function clampW(png, x, width) {
  return Math.max(1, Math.min(Math.round(width), png.width - clampX(png, x)));
}

function clampH(png, y, height) {
  return Math.max(1, Math.min(Math.round(height), png.height - clampY(png, y)));
}
