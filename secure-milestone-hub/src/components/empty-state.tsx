import type { ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { Link } from "@tanstack/react-router";
import { cn } from "@/lib/utils";

export function EmptyState({
  icon,
  title,
  body,
  actionLabel,
  actionTo,
  onAction,
  secondary,
  className,
}: {
  icon?: ReactNode;
  title: string;
  body: string;
  actionLabel: string;
  actionTo?: string | undefined;
  onAction?: (() => void) | undefined;
  secondary?: ReactNode;
  className?: string | undefined;
}) {
  return (
    <div
      className={cn(
        "surface-card flex flex-col items-center gap-4 px-6 py-14 text-center",
        className,
      )}
    >
      <div
        className="grid h-16 w-16 place-items-center rounded-2xl border border-border bg-secondary text-primary"
        aria-hidden
      >
        {icon ?? <DocGlyph />}
      </div>
      <div className="max-w-sm space-y-1.5">
        <h3 className="text-lg text-foreground">{title}</h3>
        <p className="text-sm leading-relaxed text-muted-foreground">{body}</p>
      </div>
      {actionTo ? (
        <Button asChild className="press">
          <Link to={actionTo}>{actionLabel}</Link>
        </Button>
      ) : (
        <Button onClick={onAction} className="press">
          {actionLabel}
        </Button>
      )}
      {secondary}
    </div>
  );
}

function DocGlyph() {
  return (
    <svg viewBox="0 0 48 48" className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M13 6h15l7 7v29H13z" strokeLinejoin="round" />
      <path d="M28 6v7h7" strokeLinejoin="round" />
      <path d="M18 24h12M18 31h8" strokeLinecap="round" />
    </svg>
  );
}
