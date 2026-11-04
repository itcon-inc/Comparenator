import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createServer } from 'node:http';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { capture } from '../scripts/capture.mjs';

test('real Chromium captures missing/changed content and honors forced refresh', async () => {
  let text = 'Original content';
  let color = 'black';
  let token = 0;
  const server = createServer((request, response) => {
    if (request.url === '/missing') {
      response.writeHead(404);
      response.end('Missing');
      return;
    }
    if (request.url === '/style.css') {
      response.writeHead(200, { 'Content-Type': 'text/css' });
      response.end(`body { color: ${color}; }`);
      return;
    }
    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end(`<!doctype html><html><head><link rel="stylesheet" href="/style.css"></head>
      <body><a class="comparenator-page-link">Comparenator</a><h1>${text}</h1>
      <input name="form_token" value="${token++}"><script>window.token = ${token};</script>
      </body></html>`);
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const directory = await mkdtemp(join(tmpdir(), 'comparenator-browser-test-'));
  const output = join(directory, 'capture.png');
  const url = `http://127.0.0.1:${server.address().port}/`;
  const options = { url, width: 375, height: 812, output };
  try {
    const first = await capture(options);
    assert.equal(first.changed, true);
    assert.equal(first.fingerprint.length, 64);
    const png = await readFile(output);
    assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    assert.equal(png.readUInt32BE(16), 375);
    assert.equal(png.readUInt32BE(20), 812);
    const mtime = (await stat(output)).mtimeMs;

    const unchanged = await capture({ ...options, previousFingerprint: first.fingerprint });
    assert.equal(unchanged.changed, false);
    assert.equal((await stat(output)).mtimeMs, mtime, 'unchanged capture must not rewrite the PNG');

    text = 'Updated content';
    const changed = await capture({ ...options, previousFingerprint: first.fingerprint });
    assert.equal(changed.changed, true);
    assert.notEqual(changed.fingerprint, first.fingerprint);
    assert.notDeepEqual(await readFile(output), png);

    color = 'red';
    const restyled = await capture({ ...options, previousFingerprint: changed.fingerprint });
    assert.equal(restyled.changed, true, 'changes in stylesheet bytes must invalidate the capture');

    const refreshed = await capture({ ...options, previousFingerprint: restyled.fingerprint, force: true });
    assert.equal(refreshed.changed, true);
    assert.equal(refreshed.fingerprint, restyled.fingerprint);

    await assert.rejects(capture({ ...options, url: `${url}missing` }), /HTTP 404/);
    await assert.rejects(capture({ ...options, url: 'file:///etc/hosts' }), /HTTP or HTTPS/);
    await assert.rejects(capture({ ...options, width: 10000 }), /Viewport/);
  }
  finally {
    await new Promise((resolve) => server.close(resolve));
    await rm(directory, { recursive: true });
  }
});
