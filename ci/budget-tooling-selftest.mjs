#!/usr/bin/env node

// The second, unsaved npm install in budgets re-resolved the app's locked
// source-map-js to a just-published version whose tarball returned 404. Drive
// real npm against a loopback registry with that exact failure; no public npm,
// Chrome download, application, or database is needed.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const tools = {
    '@lhci/cli': '0.14.0',
    puppeteer: '23.11.1',
    '@axe-core/puppeteer': '4.10.2',
    'axe-core': '4.10.2',
};
const work = await mkdtemp(join(tmpdir(), 'budget-tooling-selftest.'));
const requests = [];
const tarballs = new Map();
const server = createServer((req, res) => {
    const path = decodeURIComponent(req.url);
    requests.push(path);
    if (tarballs.has(path)) {
        res.end(tarballs.get(path));
        return;
    }
    const name = path.slice(1);
    if (name === 'source-map-js' || Object.hasOwn(tools, name)) {
        const versions = name === 'source-map-js' ? ['1.2.1', '1.2.2'] : [tools[name]];
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({
            name,
            'dist-tags': { latest: versions.at(-1) },
            versions: Object.fromEntries(versions.map((version) => [version, {
                name, version, dist: { tarball: `${registry}/${name}/-/${version}.tgz` },
            }])),
        }));
        return;
    }
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Not found' }));
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const registry = `http://127.0.0.1:${server.address().port}`;

function npm(cwd, args, cache) {
    return new Promise((resolve, reject) => {
        const child = spawn('npm', [...args, '--ignore-scripts', '--no-audit', '--no-fund', '--update-notifier=false',
            `--registry=${registry}`, `--cache=${join(work, cache)}`, '--fetch-retries=0'], {
            cwd,
            env: { ...process.env, NODE_ENV: 'development', NPM_CONFIG_USERCONFIG: join(work, 'empty.npmrc'),
                NPM_CONFIG_GLOBALCONFIG: join(work, 'empty-global.npmrc') },
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: 30_000,
        });
        let output = '';
        child.stdout.on('data', (data) => { output += data; });
        child.stderr.on('data', (data) => { output += data; });
        child.on('error', reject);
        child.on('close', (code, signal) => resolve({ code, signal, output }));
    });
}

async function fixture(name, includeTools, sourceMapVersion = '1.2.1') {
    const dir = join(work, name);
    await mkdir(dir);
    const manifest = { name: 'budget-install-fixture', version: '1.0.0', private: true,
        devDependencies: { 'source-map-js': '^1.2.1', ...(includeTools ? tools : {}) } };
    const packages = { '': { ...manifest },
        'node_modules/source-map-js': locked('source-map-js', sourceMapVersion) };
    if (includeTools) {
        for (const [tool, version] of Object.entries(tools)) {
            packages[`node_modules/${tool}`] = locked(tool, version);
        }
    }
    await writeFile(join(dir, 'package.json'), JSON.stringify(manifest));
    await writeFile(join(dir, 'package-lock.json'), JSON.stringify({
        name: manifest.name, version: manifest.version, lockfileVersion: 3, requires: true, packages,
    }));
    return dir;
}

function locked(name, version) {
    const path = `/${name}/-/${version}.tgz`;
    const tarball = tarballs.get(path);
    return { version, resolved: `${registry}${path}`, dev: true,
        ...(tarball ? { integrity: `sha512-${createHash('sha512').update(tarball).digest('base64')}` } : {}) };
}

try {
    await writeFile(join(work, 'empty.npmrc'), '');
    await writeFile(join(work, 'empty-global.npmrc'), '');
    for (const [index, [name, version]] of Object.entries({ 'source-map-js': '1.2.1', ...tools }).entries()) {
        const dir = join(work, `tarball-${index}`);
        await mkdir(join(dir, 'package'), { recursive: true });
        await writeFile(join(dir, 'package/package.json'), JSON.stringify({ name, version }));
        const archive = join(dir, 'package.tgz');
        const result = spawnSync('tar', ['-czf', archive, '-C', dir, 'package'], { encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        tarballs.set(`/${name}/-/${version}.tgz`, await readFile(archive));
    }

    const old = await fixture('unsaved-install', false);
    const initial = await npm(old, ['ci', '--include=dev'], 'old-cache');
    assert.equal(initial.code, 0, initial.output);
    requests.length = 0;
    const failed = await npm(old, ['i', '--no-save', '--no-package-lock',
        ...Object.entries(tools).map(([name, version]) => `${name}@${version}`)], 'old-cache');
    assert.equal(failed.code, 1, failed.output);
    assert.match(failed.output, /E404/);
    assert.ok(requests.includes('/source-map-js/-/1.2.2.tgz'), failed.output);
    console.log('PASS  unsaved install re-resolves locked 1.2.1 and fails on the advertised 1.2.2 tarball');

    const fixed = await fixture('locked-install', true);
    const before = await readFile(join(fixed, 'package-lock.json'), 'utf8');
    requests.length = 0;
    const passed = await npm(fixed, ['ci', '--include=dev'], 'fixed-cache');
    assert.equal(passed.code, 0, passed.output);
    assert.equal(await readFile(join(fixed, 'package-lock.json'), 'utf8'), before);
    assert.ok(requests.includes('/source-map-js/-/1.2.1.tgz'));
    assert.ok(requests.every((path) => tarballs.has(path)), JSON.stringify(requests));
    for (const [name, version] of Object.entries({ 'source-map-js': '1.2.1', ...tools })) {
        const installed = JSON.parse(await readFile(join(fixed, 'node_modules', name, 'package.json'), 'utf8'));
        assert.equal(installed.version, version);
    }
    console.log('PASS  npm ci installs every locked tool without consulting newer registry metadata');

    const missing = await fixture('missing-locked-tarball', true, '1.2.2');
    const missingResult = await npm(missing, ['ci', '--include=dev'], 'missing-cache');
    assert.equal(missingResult.code, 1, missingResult.output);
    assert.match(missingResult.output, /E404/);
    console.log('PASS  a genuinely missing locked tarball still fails — no retry or error suppression');

    const manifest = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));
    const lock = JSON.parse(await readFile(new URL('../package-lock.json', import.meta.url), 'utf8'));
    for (const [name, version] of Object.entries(tools)) {
        assert.equal(manifest.devDependencies[name], version, `${name} must remain exactly pinned`);
        assert.equal(lock.packages[''].devDependencies[name], version);
        assert.equal(lock.packages[`node_modules/${name}`].version, version);
        assert.ok(lock.packages[`node_modules/${name}`].integrity, `${name} must have a locked integrity`);
    }
    const workflow = await readFile(new URL('../.github/workflows/ci.yml', import.meta.url), 'utf8');
    const budgets = workflow.split(/^  budgets:\s*$/m)[1].split(/^  [a-z][\w-]*:\s*$/m)[0];
    assert.match(budgets, /run: npm ci --include=dev/);
    assert.doesNotMatch(budgets, /\bnpm (?:i|install)\b/);
    assert.match(budgets, /npx --no-install lhci autorun/);
    assert.match(workflow.split(/^  pest:\s*$/m)[0], /run: node ci\/budget-tooling-selftest\.mjs/);
    console.log('PASS  real manifests and budgets use only the locked toolchain; static runs this guard');
} finally {
    await new Promise((resolve) => server.close(resolve));
    await rm(work, { recursive: true, force: true });
}
