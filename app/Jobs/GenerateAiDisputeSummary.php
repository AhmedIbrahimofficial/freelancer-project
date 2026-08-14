<?php

namespace App\Jobs;

use App\Models\AiDisputeSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateAiDisputeSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly AiDisputeSummary $summary,
    ) {}

    public function handle(): void
    {
        $dispute  = $this->summary->dispute()->with('evidence.user:id,name,role')->first();
        $isSuggest = $this->summary->type === 'suggestion';

        // Build evidence thread text
        $thread = $dispute->evidence->map(function ($e) {
            $role = $e->user->role ?? 'unknown';
            $msg  = $e->message ?? '[file attachment: ' . ($e->file_name ?? 'unnamed') . ']';
            return "[{$role} — {$e->user->name}]: {$msg}";
        })->join("\n\n");

        $systemPrompt = $isSuggest
            ? 'You are a neutral dispute mediator assistant. Based on the evidence provided, suggest a fair resolution. Clearly label your output as AI-generated and non-binding.'
            : 'You are a neutral dispute mediator assistant. Summarize the evidence thread clearly and objectively. Identify the key points from each party. Clearly label your output as AI-generated.';

        $userPrompt = "Dispute reason: {$dispute->reason}\n\nEvidence thread:\n{$thread}";

        $response = Http::withToken(config('services.anthropic.api_key'))
            ->withHeaders(['anthropic-version' => '2023-06-01'])
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 1024,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
            $this->summary->update(['status' => 'failed']);
            return;
        }

        $data   = $response->json();
        $text   = $data['content'][0]['text'] ?? '';
        $usage  = $data['usage'] ?? [];

        $this->summary->update([
            'summary_text'         => $text,
            'suggested_resolution' => $isSuggest ? $text : null,
            'model_version'        => $data['model'] ?? 'claude-3-5-sonnet',
            'input_tokens'         => $usage['input_tokens'] ?? null,
            'output_tokens'        => $usage['output_tokens'] ?? null,
            'status'               => 'completed',
        ]);

        // TODO: broadcast to frontend via Pusher/Echo so UI updates in real-time
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateAiDisputeSummary job failed', [
            'summary_id' => $this->summary->id,
            'error'      => $exception->getMessage(),
        ]);
        $this->summary->update(['status' => 'failed']);
    }
}
