import http from 'node:http';

const parsedPort = Number.parseInt(process.env.PORT ?? '3000', 10);
const port = Number.isInteger(parsedPort) && parsedPort > 0 && parsedPort <= 65535
  ? parsedPort
  : 3000;
const host = process.env.HOST || '127.0.0.1';

let playwrightAvailable = false;
try {
  await import('playwright');
  playwrightAvailable = true;
} catch {
  // Health reports the missing package without exposing loader paths/errors.
}

function sendJson(response, status, payload) {
  const body = JSON.stringify(payload);
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  response.end(body);
}

const server = http.createServer((request, response) => {
  try {
    const pathname = new URL(
      request.url ?? '/',
      `http://${request.headers.host || 'localhost'}`,
    ).pathname;
    const isHealth = pathname === '/health' || pathname.endsWith('/health');

    if (request.method === 'GET' && isHealth) {
      sendJson(response, playwrightAvailable ? 200 : 503, {
        status: playwrightAvailable ? 'ok' : 'degraded',
        service: 'vendify-restricted-browser-runtime',
        node: {
          available: true,
          version: process.version,
        },
        playwright: {
          package_available: playwrightAvailable,
        },
        browser_execution_exposed: false,
      });
      return;
    }

    // Include only the parsed path temporarily to diagnose cPanel mount-prefix
    // behavior. Query parameters and headers are intentionally excluded.
    sendJson(response, 404, { status: 'not_found', pathname });
  } catch {
    sendJson(response, 500, { status: 'error' });
  }
});

server.on('clientError', (_error, socket) => {
  if (socket.writable) {
    socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n');
  }
});

server.listen(port, host, () => {
  console.log(`Vendify browser runtime health server listening on ${host}:${port}`);
});

function fatal(kind) {
  console.error(`Vendify browser runtime stopped after ${kind}.`);
  server.close(() => process.exit(1));
  setTimeout(() => process.exit(1), 1000).unref();
}

process.on('uncaughtException', () => fatal('an uncaught exception'));
process.on('unhandledRejection', () => fatal('an unhandled rejection'));

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
