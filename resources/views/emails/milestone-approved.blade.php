@extends('emails._layout')

@section('subject', 'Milestone approved')

@section('content')
<h1>Your milestone has been approved</h1>
<p>Hello {{ $recipient->name }},</p>
<p>{{ $milestone->contract->client->name }} has approved your milestone. The escrow payment will be released.</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $milestone->contract->title }}</dd>
  <dt>Milestone</dt>
  <dd>{{ $milestone->title }}</dd>
  <dt>Amount released</dt>
  <dd>${{ number_format($milestone->amount, 2) }} {{ $milestone->contract->currency }}</dd>
  <dt>Approved</dt>
  <dd>{{ $milestone->approved_at?->format('d M Y, H:i') }} UTC</dd>
</dl>

<p>Funds will arrive in your connected payout account within 1–2 business days after the transfer completes.</p>

<a href="{{ config('app.url') }}/contracts/{{ $milestone->contract_id }}" class="btn">View contract</a>
@endsection
