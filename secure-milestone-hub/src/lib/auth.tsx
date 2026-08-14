/**
 * Auth context — wraps the Sanctum token lifecycle.
 * Provides the current user, login, register, and logout actions.
 * Token is persisted to localStorage so page refreshes stay logged in.
 */

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { auth, clearToken, getToken, setToken, type AuthUser } from "./api";
import { disconnectEcho } from "./echo";

interface AuthState {
  user: AuthUser | null;
  token: string | null;
  loading: boolean;
  error: string | null;
}

interface AuthActions {
  login: (email: string, password: string) => Promise<void>;
  register: (data: {
    name: string;
    email: string;
    password: string;
    role: "client" | "freelancer";
  }) => Promise<void>;
  logout: () => Promise<void>;
  clearError: () => void;
}

const AuthContext = createContext<(AuthState & AuthActions) | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [token, setTokenState] = useState<string | null>(getToken);
  const [loading, setLoading] = useState(Boolean(getToken()));
  const [error, setError] = useState<string | null>(null);

  // On mount: if we have a stored token, validate it by fetching /me
  useEffect(() => {
    const stored = getToken();
    if (!stored) {
      setLoading(false);
      return;
    }
    auth
      .me()
      .then((u) => {
        setUser(u);
      })
      .catch(() => {
        // Token is stale — clear it
        clearToken();
        setTokenState(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    setError(null);
    const res = await auth.login({ email, password });
    setToken(res.token);
    setTokenState(res.token);
    setUser(res.user);
  }, []);

  const register = useCallback(
    async (data: {
      name: string;
      email: string;
      password: string;
      role: "client" | "freelancer";
    }) => {
      setError(null);
      const res = await auth.register(data);
      setToken(res.token);
      setTokenState(res.token);
      setUser(res.user);
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await auth.logout();
    } catch {
      // ignore — always clear locally
    }
    clearToken();
    disconnectEcho();
    setTokenState(null);
    setUser(null);
  }, []);

  const clearError = useCallback(() => setError(null), []);

  return (
    <AuthContext.Provider
      value={{ user, token, loading, error, login, register, logout, clearError }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside AuthProvider");
  return ctx;
}
