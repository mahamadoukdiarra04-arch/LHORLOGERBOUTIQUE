import { createServer } from "node:http";
import { createReadStream } from "node:fs";
import { stat } from "node:fs/promises";
import { extname, resolve, sep } from "node:path";
import { Readable } from "node:stream";
import worker from "./dist/server/index.js";

const port = Number(process.env.PORT ?? 3000);
const clientDirectory = resolve(process.cwd(), "dist", "client");
const mimeTypes = {
  ".css": "text/css; charset=utf-8",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".jpeg": "image/jpeg",
  ".jpg": "image/jpeg",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".webp": "image/webp",
  ".woff2": "font/woff2",
};

const assets = {
  async fetch(request) {
    const assetPath = assetPathFor(request.url);
    if (!assetPath) return new Response("Not found", { status: 404 });

    try {
      const file = await stat(assetPath);
      if (!file.isFile()) return new Response("Not found", { status: 404 });
      return new Response(Readable.toWeb(createReadStream(assetPath)), {
        headers: {
          "Content-Type": mimeTypes[extname(assetPath).toLowerCase()] ?? "application/octet-stream",
          "Cache-Control": assetPath.includes(`${sep}_next${sep}static${sep}`) ? "public, max-age=31536000, immutable" : "public, max-age=3600",
        },
      });
    } catch {
      return new Response("Not found", { status: 404 });
    }
  },
};

createServer(async (nodeRequest, nodeResponse) => {
  try {
    const request = toWebRequest(nodeRequest);
    const response = await worker.fetch(request, { ASSETS: assets }, { waitUntil: (promise) => promise.catch(() => undefined), passThroughOnException() {} });
    nodeResponse.statusCode = response.status;
    response.headers.forEach((value, key) => nodeResponse.setHeader(key, value));
    nodeResponse.setHeader("X-Content-Type-Options", "nosniff");
    nodeResponse.setHeader("X-Frame-Options", "DENY");
    nodeResponse.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");

    if (!response.body || nodeRequest.method === "HEAD") {
      nodeResponse.end();
      return;
    }
    Readable.fromWeb(response.body).pipe(nodeResponse);
  } catch {
    nodeResponse.writeHead(500, { "Content-Type": "text/plain; charset=utf-8" });
    nodeResponse.end("Une erreur est survenue.");
  }
}).listen(port, "0.0.0.0", () => {
  console.log(`L’Horloger est prêt sur le port ${port}.`);
});

function toWebRequest(nodeRequest) {
  const forwardedProtocol = nodeRequest.headers["x-forwarded-proto"];
  const protocol = Array.isArray(forwardedProtocol) ? forwardedProtocol[0] : forwardedProtocol ?? "http";
  const forwardedHost = nodeRequest.headers["x-forwarded-host"];
  const host = Array.isArray(forwardedHost) ? forwardedHost[0] : forwardedHost ?? nodeRequest.headers.host ?? `localhost:${port}`;
  const headers = new Headers();
  Object.entries(nodeRequest.headers).forEach(([key, value]) => {
    if (value !== undefined) headers.set(key, Array.isArray(value) ? value.join(", ") : value);
  });

  const init = { method: nodeRequest.method, headers };
  if (nodeRequest.method !== "GET" && nodeRequest.method !== "HEAD") {
    return new Request(`${protocol}://${host}${nodeRequest.url ?? "/"}`, { ...init, body: Readable.toWeb(nodeRequest), duplex: "half" });
  }
  return new Request(`${protocol}://${host}${nodeRequest.url ?? "/"}`, init);
}

function assetPathFor(value) {
  let pathname;
  try {
    pathname = decodeURIComponent(new URL(value).pathname);
  } catch {
    return null;
  }
  const resolved = resolve(clientDirectory, `.${pathname}`);
  return resolved === clientDirectory || resolved.startsWith(`${clientDirectory}${sep}`) ? resolved : null;
}
