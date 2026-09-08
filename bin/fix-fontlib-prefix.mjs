/**
 * Prefix php-font-lib’s dynamically constructed class names that Strauss cannot rewrite. Fail
 * if no targets match so dependency changes require review.
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.join(process.cwd(), 'vendor/vendor-prefixed/dompdf/php-font-lib/src/FontLib');

const TARGETS = [
    { file: 'Font.php', from: '"FontLib\\\\$class"', to: '"FundKit\\\\Vendor\\\\FontLib\\\\$class"' },
    {
        file: 'TrueType/File.php',
        from: '"FontLib\\\\$type\\\\TableDirectoryEntry"',
        to: '"FundKit\\\\Vendor\\\\FontLib\\\\$type\\\\TableDirectoryEntry"',
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
