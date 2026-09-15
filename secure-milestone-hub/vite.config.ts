// @lovable.dev/vite-tanstack-config already includes the following — do NOT add them manually
// or the app will break with duplicate plugins:
//   - TanStack devtools (dev-only, first), tanstackStart, viteReact, tailwindcss, tsConfigPaths,
//     nitro (build-only using cloudflare as a default target), VITE_* env injection, @ path alias,
//     React/TanStack dedupe, error logger plugins, and sandbox detection (port/host/strictPort).
import { defineConfig } from "@lovable.dev/vite-tanstack-config";

export default defineConfig({
  tanstackStart: {
    server: { entry: "server" },
    // Vercel preset: outputs to .vercel/output for Vercel serverless deployment.
    // Override via NITRO_PRESET env var for other targets (node-server, cloudflare-pages, etc.)
    nitro: {
      preset: process.env.NITRO_PRESET ?? "vercel",
    },
  },
});
