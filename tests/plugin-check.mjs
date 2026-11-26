// Runs the official Plugin Check against the plugin inside WordPress Playground.
// The blueprint patches PHPCS in the sandbox copy only (WASM PHP has no stdin or file locks).
// Usage: node tests/plugin-check.mjs   (WordPress version: WWFW_WP=beta|nightly|7.1.5, default latest)
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import os from 'node:os';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cli = path.join(root, 'tools', 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const blueprint = JSON.parse(readFileSync(path.join(root, 'tests', 'plugin-check', 'blueprint.json'), 'utf8'));
blueprint.preferredVersions.wp = process.env.WWFW_WP || blueprint.preferredVersions.wp;
const blueprintPath = path.join(mkdtempSync(path.join(os.tmpdir(), 'wwfw-pc-')), 'blueprint.json');
writeFileSync(blueprintPath, JSON.stringify(blueprint));
const args = [
  cli, 'php',
  '--blueprint', blueprintPath,
  '--mount-dir', path.join(root, 'plugin', 'hookvigil-for-woocommerce'), '/wordpress/wp-content/plugins/hookvigil-for-woocommerce',
  '--mount-dir', path.join(root, 'tests', 'plugin-check'), '/plugin-check',
  '--', '/plugin-check/run.php',
];
const run = spawnSync(process.execPath, args, { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
const out = `${run.stdout || ''}${run.stderr || ''}`;
process.stdout.write(out || 'No output.\n');
// Pass only when Plugin Check finished and reported no ERROR rows; a failed boot or download is a failure.
const finished = /Checks complete|^file,line,type,code,message$/m.test(out);
process.exit(!finished || /,ERROR,/.test(out) ? 1 : 0);
