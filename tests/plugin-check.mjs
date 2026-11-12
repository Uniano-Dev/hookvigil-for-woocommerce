// Runs the official Plugin Check against the plugin inside WordPress Playground.
// The blueprint patches PHPCS in the sandbox copy only (WASM PHP has no stdin or file locks).
// Usage: node tests/plugin-check.mjs
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cli = path.join(root, 'tools', 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const args = [
  cli, 'php',
  '--blueprint', path.join(root, 'tests', 'plugin-check', 'blueprint.json'),
  '--mount-dir', path.join(root, 'plugin', 'hookvigil-for-woocommerce'), '/wordpress/wp-content/plugins/hookvigil-for-woocommerce',
  '--mount-dir', path.join(root, 'tests', 'plugin-check'), '/plugin-check',
  '--', '/plugin-check/run.php',
];
const run = spawnSync(process.execPath, args, { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
const out = `${run.stdout || ''}${run.stderr || ''}`;
process.stdout.write(out || 'No findings.\n');
process.exit(/,ERROR,/.test(out) ? 1 : 0);
