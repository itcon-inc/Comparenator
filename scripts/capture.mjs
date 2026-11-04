import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

/**
 * Fingerprint rendered content, excluding per-request script data and tokens.
 */
export async function fingerprint(page) {
  const content = await page.evaluate(() => {
    const root = document.documentElement.cloneNode(true);
    root.querySelectorAll(
      'script, .comparenator-page-link, #toolbar-administration, input[name="form_build_id"], input[name="form_token"]',
    ).forEach((element) => element.remove());
    root.querySelectorAll('[nonce]').forEach((element) => element.removeAttribute('nonce'));
    return root.outerHTML;
  });
  return createHash('sha256').update(content).digest('hex');
}

export async function capture(options, browserType = chromium) {
  const { url, width, height, output, previousFingerprint, force = false } = options;
  if (!['http:', 'https:'].includes(new URL(url).protocol)) {
    throw new Error('Capture requires an HTTP or HTTPS URL.');
  }
  if (![width, height].every((value) => Number.isInteger(value) && value >= 1 && value <= 4096)) {
    throw new Error('Viewport dimensions must be integers between 1 and 4096.');
  }
  const browser = await browserType.launch({ headless: true });
  try {
    const context = await browser.newContext({
      viewport: { width, height },
      deviceScaleFactor: 1,
      reducedMotion: 'reduce',
      serviceWorkers: 'block',
    });
    const page = await context.newPage();
    const assets = [];
    page.on('response', (response) => {
      if (['stylesheet', 'image', 'font'].includes(response.request().resourceType()) && response.ok()) {
        assets.push(response.body().then(
          (body) => [response.url(), createHash('sha256').update(body).digest('hex')],
          (error) => ({ error }),
        ));
      }
    });
    const response = await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
    if (!response || !response.ok()) {
      throw new Error(`Page returned HTTP ${response?.status() ?? 'no response'}.`);
    }
    if (!response.headers()['content-type']?.includes('text/html')) {
      throw new Error('Capture requires an HTML page.');
    }
    if (new URL(page.url()).origin !== new URL(url).origin) {
      throw new Error('Capture redirected outside the configured environment.');
    }
    await page.evaluate(async () => {
      await document.fonts.ready;
      await Promise.all([...document.images]
        .filter((image) => image.currentSrc)
        .map((image) => image.decode()));
    });
    await page.addStyleTag({
      content: '.comparenator-page-link, #toolbar-administration { display: none !important; }',
    });
    const resourceHashes = await Promise.all(assets);
    const failed = resourceHashes.find((resource) => resource.error);
    if (failed) {
      throw new Error(`Unable to read a capture asset: ${failed.error.message}`);
    }
    const hash = createHash('sha256')
      .update(await fingerprint(page))
      .update(JSON.stringify(resourceHashes.sort((a, b) => a[0].localeCompare(b[0]))))
      .digest('hex');
    const changed = force || hash !== previousFingerprint;
    if (changed) {
      await page.screenshot({
        path: output,
        type: 'png',
        fullPage: true,
        animations: 'disabled',
      });
    }
    return { fingerprint: hash, changed };
  }
  finally {
    await browser.close();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    let input = '';
    for await (const chunk of process.stdin) input += chunk;
    const result = await capture(JSON.parse(input));
    process.stdout.write(JSON.stringify(result));
  }
  catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}

