/**
 * Stitch recorded frames into an animated GIF.
 *
 * sharp is used only to decode and resize each PNG to raw RGBA; the GIF itself
 * is written by gifenc.
 *
 * Why not sharp's own multi-page GIF path: it needs `pageHeight` on the input
 * metadata and silently ignored it here, producing a *single* frame 27907px tall
 * while still printing "43 frames". That is a false success that looks exactly
 * like a real encode until someone opens the file. Encoding the pages explicitly
 * removes the whole class. The frame-count assertion at the end is the check.
 *
 * Usage: node ci/browser/gif.mjs <framesdir> <out.gif> [width] [delay-ms]
 */
import { readdirSync, writeFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import sharp from 'sharp';
// gifenc's package entry resolves to CommonJS under Node's ESM loader, so the
// named exports are not available directly — destructure the default instead.
import gifenc from 'gifenc';

const { GIFEncoder, quantize, applyPalette } = gifenc;

const DIR = process.argv[2];
const OUT = process.argv[3];
const WIDTH = Number(process.argv[4] ?? 300);
const DELAY = Number(process.argv[5] ?? 110);

if (!DIR || !OUT) {
  console.error('usage: node ci/browser/gif.mjs <framesdir> <out.gif> [width] [delay-ms]');
  process.exit(2);
}

const files = readdirSync(DIR).filter((f) => f.endsWith('.png')).sort();
if (files.length === 0) throw new Error(`no frames in ${DIR}`);

const meta = await sharp(join(DIR, files[0])).metadata();
const height = Math.round((meta.height / meta.width) * WIDTH);

const gif = GIFEncoder();

for (const f of files) {
  const { data } = await sharp(join(DIR, f))
    .resize(WIDTH, height, { fit: 'fill' })
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true });

  const rgba = new Uint8ClampedArray(data.buffer, data.byteOffset, data.length);
  // Per-frame palette: a dark hero and a near-black body band badly under one
  // shared 256-colour palette.
  const palette = quantize(rgba, 256);
  const index = applyPalette(rgba, palette);
  gif.writeFrame(index, WIDTH, height, { palette, delay: DELAY });
}

gif.finish();
writeFileSync(OUT, Buffer.from(gif.bytes()));

// Read the file back and count the frames actually encoded. This is the guard
// against the sharp failure above: never report a frame count from the input.
const written = await sharp(OUT, { animated: true }).metadata();
const encoded = written.pages ?? 1;
const bytes = statSync(OUT).size;
console.log(`${files.length} frames -> ${OUT} (${WIDTH}x${height}, ${DELAY}ms, ${bytes} bytes, ${encoded} encoded)`);

if (encoded !== files.length) {
  console.error(`FAIL: encoded ${encoded} frames from ${files.length} inputs — the GIF is not animated`);
  process.exit(1);
}
