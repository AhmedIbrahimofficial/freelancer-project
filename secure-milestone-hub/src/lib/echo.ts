/**
 * Laravel Echo + Pusher setup.
 *
 * Call getEcho() to get (or lazily create) the singleton Echo instance.
 * Requires these env vars in .env.local:
 *   VITE_PUSHER_APP_KEY=your_key
 *   VITE_PUSHER_APP_CLUSTER=mt1
 *   VITE_API_URL=http://localhost:8000
 */

import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { getToken } from "./api";

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

// Pusher must be on window for Echo to find it
if (typeof window !== "undefined") {
  window.Pusher = Pusher;
}

let _echo: Echo<"pusher"> | null = null;

export function getEcho(): Echo<"pusher"> | null {
  const key = import.meta.env.VITE_PUSHER_APP_KEY;
  if (!key) {
    // Pusher not configured — real-time features silently disabled
    return null;
  }

  if (_echo) return _echo;

  _echo = new Echo({
    broadcaster: "pusher",
    key,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? "mt1",
    forceTLS: true,
    // Sanctum token auth for private channels
    authEndpoint: `${import.meta.env.VITE_API_URL ?? "http://localhost:8000"}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${getToken() ?? ""}`,
        Accept: "application/json",
      },
    },
  });

  return _echo;
}

/** Tear down the Echo connection (call on logout). */
export function disconnectEcho(): void {
  _echo?.disconnect();
  _echo = null;
}
