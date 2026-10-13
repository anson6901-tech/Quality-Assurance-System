/**
 * Generates resources/css/dark-theme.css.
 *
 * Most screens use hard-coded light colours (bg-white, bg-[#eefaf3], text-[#063f22] ...)
 * instead of theme tokens, so they stay light when the `.dark` class is applied while the
 * token-based text turns light — leaving unreadable light-on-light text. This script scans
 * every Vue/TS file for colour utilities and writes `.dark` overrides that map light surfaces
 * to dark ones and dark text to light text, keeping each colour's hue.
 *
 * Elements that already declare their own `dark:` colour for the same property are left alone.
 *
 * Run with: npm run theme:dark
 */
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const sourceDir = join(root, 'resources/js');
const outputFile = join(root, 'resources/css/dark-theme.css');

// Tailwind palette shades used in the app (sRGB hex equivalents).
const palette = {
    white: '#ffffff',
    black: '#000000',
    'amber-50': '#fffbeb',
    'amber-300': '#fcd34d',
    'amber-500': '#f59e0b',
    'amber-700': '#b45309',
    'amber-800': '#92400e',
    'amber-900': '#78350f',
    'amber-950': '#451a03',
    'emerald-700': '#047857',
    'green-500': '#22c55e',
    'green-600': '#16a34a',
    'neutral-100': '#f5f5f5',
    'neutral-200': '#e5e5e5',
    'neutral-500': '#737373',
    'neutral-600': '#525252',
    'neutral-800': '#262626',
    'neutral-900': '#171717',
    'red-50': '#fef2f2',
    'red-200': '#fecaca',
    'red-300': '#fca5a5',
    'red-600': '#dc2626',
    'red-700': '#b91c1c',
    'red-800': '#991b1b',
    'zinc-900': '#18181b',
};

const kinds = {
    bg: { property: 'background-color', role: 'surface', guard: 'dark:bg-' },
    from: {
        property: '--tw-gradient-from',
        role: 'surface',
        guard: 'dark:from-',
    },
    via: { property: '--tw-gradient-via', role: 'surface', guard: 'dark:via-' },
    to: { property: '--tw-gradient-to', role: 'surface', guard: 'dark:to-' },
    text: { property: 'color', role: 'text', guard: 'dark:text-' },
    border: { property: 'border-color', role: 'border', guard: 'dark:border-' },
    'border-t': {
        property: 'border-top-color',
        role: 'border',
        guard: 'dark:border-',
    },
    'border-b': {
        property: 'border-bottom-color',
        role: 'border',
        guard: 'dark:border-',
    },
    'border-l': {
        property: 'border-left-color',
        role: 'border',
        guard: 'dark:border-',
    },
    'border-r': {
        property: 'border-right-color',
        role: 'border',
        guard: 'dark:border-',
    },
    ring: { property: '--tw-ring-color', role: 'border', guard: 'dark:ring-' },
};

const variantSelectors = {
    hover: (s) => `${s}:hover`,
    focus: (s) => `${s}:focus`,
    'focus-visible': (s) => `${s}:focus-visible`,
    placeholder: (s) => `${s}::placeholder`,
    before: (s) => `${s}::before`,
};

const classPattern =
    /(?<![\w:-])((?:(?:[a-z-]+|\[[^\]\s]+\]):)*)(bg|text|border(?:-[tblr])?|from|via|to|ring)-(\[#[0-9a-fA-F]{3,8}\]|[a-z]+(?:-\d{2,3})?)(?:\/(\d{1,3}))?(?![\w-])/g;

function files(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return files(path);
        }

        return /\.(vue|ts)$/.test(name) ? [path] : [];
    });
}

function hexToRgb(hex) {
    let h = hex.replace('#', '');

    if (h.length === 3) {
        h = [...h].map((c) => c + c).join('');
    }

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
}

function rgbToHsl([r, g, b]) {
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;

    if (max === min) {
        return [0, 0, l];
    }

    const d = max - min;
    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    const h =
        max === r
            ? (g - b) / d + (g < b ? 6 : 0)
            : max === g
              ? (b - r) / d + 2
              : (r - g) / d + 4;

    return [h * 60, s, l];
}

const hsl = (h, s, l, alpha) =>
    `hsl(${h.toFixed(0)} ${(s * 100).toFixed(0)}% ${(l * 100).toFixed(0)}%${alpha < 1 ? ` / ${alpha}` : ''})`;

/** Returns the dark-mode colour, or null when the original already works on a dark page. */
function darkColour(role, hex, alpha) {
    const [h, s, l] = rgbToHsl(hexToRgb(hex));

    if (role === 'surface') {
        if (l < 0.6 || alpha <= 0.3) {
            return null;
        } // brand fills, dark panels and translucent overlays

        return hsl(h, Math.min(s, 0.45), 0.07 + (1 - l) * 0.5, alpha);
    }

    if (role === 'border') {
        if (l < 0.55 || alpha <= 0.3) {
            return null;
        }

        return hsl(h, Math.min(s, 0.3), 0.17 + (1 - l) * 0.3, alpha);
    }

    if (l >= 0.5) {
        return null;
    } // text that is already light

    return hsl(h, Math.min(s, 0.55), 0.78 + (0.5 - l) * 0.36, alpha);
}

function escapeClass(name) {
    return name.replace(/([^a-zA-Z0-9_-])/g, '\\$1');
}

function selectorFor(className, variants, guard) {
    let selector = `.dark .${escapeClass(className)}:not([class*="${guard}"])`;

    for (const variant of variants) {
        if (variantSelectors[variant]) {
            selector = variantSelectors[variant](selector);
        } else if (/^\[&[>_][a-z0-9]+\]$/.test(variant)) {
            const combinator = variant[2] === '>' ? ' > ' : ' ';
            selector = `${selector}${combinator}${variant.slice(3, -1)}`;
        } else {
            return null;
        }
    }

    return selector;
}

const rules = new Map();
const skipped = new Set();

for (const file of files(sourceDir)) {
    for (const match of readFileSync(file, 'utf8').matchAll(classPattern)) {
        const [className, prefix, kindName, colourToken, opacity] = match;
        const variants = prefix
            ? prefix.slice(0, -1).split(/:(?![^[]*\])/)
            : [];

        if (variants.includes('dark')) {
            continue;
        }

        const kind = kinds[kindName];
        const hex = colourToken.startsWith('[#')
            ? colourToken.slice(1, -1)
            : palette[colourToken];

        if (!kind || !hex) {
            continue;
        }

        const alpha = opacity ? Number(opacity) / 100 : 1;
        const colour = darkColour(kind.role, hex, alpha);

        if (!colour) {
            continue;
        }

        const selector = selectorFor(className, variants, kind.guard);

        if (!selector) {
            skipped.add(className);
            continue;
        }

        rules.set(selector, `${kind.property}: ${colour};`);
    }
}

const css = [
    '/* Generated by scripts/generate-dark-theme.mjs — do not edit by hand. Run `npm run theme:dark`. */',
    ...[...rules.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(
            ([selector, declaration]) => `${selector} {\n    ${declaration}\n}`,
        ),
    '',
].join('\n');

writeFileSync(outputFile, css);
console.log(
    `Wrote ${rules.size} dark-mode rules to resources/css/dark-theme.css`,
);

if (skipped.size) {
    console.log(`Skipped (unsupported variant): ${[...skipped].join(' ')}`);
}
