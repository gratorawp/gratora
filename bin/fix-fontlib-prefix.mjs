/**
 * Finish the prefixing Strauss cannot do for php-font-lib.
 *
 * The library builds two class names by concatenation, `"FontLib\\$class"` and
 * `"FontLib\\$type\\TableDirectoryEntry"`. Only a fragment of the namespace is
 * a literal, so a rewriter matching whole names leaves them alone, and the
 * prefixed copy then asks the autoloader for a class that no longer exists.
 * Nothing shows this until a PDF is rendered, where it surfaces as
 * `Class "FontLib\TrueType\File" not found`.
 *
 * Exits non-zero when it finds nothing to do, because a silent success here
 * means the next release quietly ships a receipt path that fatals.
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.join(process.cwd(), 'vendor/vendor-prefixed/dompdf/php-font-lib/src/FontLib');

const TARGETS = [
    { file: 'Font.php', from: '"FontLib\\\\$class"', to: '"Dono\\\\Vendor\\\\FontLib\\\\$class"' },
    {
        file: 'TrueType/File.php',
        from: '"FontLib\\\\$type\\\\TableDirectoryEntry"',
        to: '"Dono\\\\Vendor\\\\FontLib\\\\$type\\\\TableDirectoryEntry"',
    },
    {
        // getFontType() reads the segment before the class name by absolute
        // position, which the added prefix shifts: index 1 was TrueType and
        // becomes Vendor. Counting from the end is the same answer prefixed or
        // not.
        file: 'TrueType/File.php',
        from: 'return $class_parts[1];',
        to: 'return $class_parts[count($class_parts) - 2];',
    },
];

if (!existsSync(ROOT)) {
    console.log('no prefixed php-font-lib; nothing to fix');
    process.exit(0);
}

let fixed = 0;
let already = 0;

for (const { file, from, to } of TARGETS) {
    const full = path.join(ROOT, file);
    const src = readFileSync(full, 'utf8');

    if (src.includes(to)) {
        already++;
        continue;
    }
    if (!src.includes(from)) {
        console.error(`fix-fontlib-prefix: expected ${from} in ${file}, found neither form.`);
        console.error('php-font-lib has changed shape. Check how it builds class names before releasing.');
        process.exit(1);
    }

    writeFileSync(full, src.split(from).join(to), 'utf8');
    fixed++;
}

console.log(
    fixed === 0
        ? `php-font-lib class names already prefixed (${already} sites)`
        : `prefixed ${fixed} dynamic class name${fixed === 1 ? '' : 's'} in php-font-lib`
);
