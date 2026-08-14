import { cp, mkdir, rm } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const source = resolve(root, "php-site");
const deploy = resolve(source, "deploy");

await rm(deploy, { recursive: true, force: true });
await mkdir(deploy, { recursive: true });
await cp(resolve(source, "app"), resolve(deploy, "app"), {
  recursive: true,
  filter: (path) => !path.endsWith("config.php"),
});
await cp(resolve(source, "public"), resolve(deploy, "public_html"), { recursive: true });
await cp(resolve(root, "public", "products"), resolve(deploy, "public_html", "products"), { recursive: true });
await cp(resolve(root, "public", "og.png"), resolve(deploy, "public_html", "og.png"));

console.log("Package PHP Hostinger prêt : php-site/deploy/");
