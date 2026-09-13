@extends('emails._layout')

@section('subject', 'Dispute funds actioned')

@section('content')
<h1>
  @if($action === 'transfer')
    Funds have been released
  @elseif($action === 'refund')
    Refund has been initiated
  @else
    Dispute has been closed
  @endif
</h1>

<p>Hello {{ $recipient->name }},</p>

@php
  $outcomeLabel = match($dispute->status) {
    'resolved_client'     => 'in favour of the client',
    'resolved_freelancer' => 'in favour of the freelancer',
    'resolved_split'      => 'as a split resolution',
    default               => 'and closed',
  };
@endphp

<p>
  The dispute for <strong>{{ $dispute->contract->title }}</strong> was resolved {{ $outcomeLabel }}.
  The corresponding financial action has now been executed.
</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $dispute->contract->title }}</dd>

  <dt>Resolution outcome</dt>
  <dd>{{ ucfirst(str_replace('_', ' ', $dispute->status)) }}</dd>

  <dt>Financial action</dt>
  <dd>
    @if($action === 'transfer')
      Funds transferred to freelancer's account
    @elseif($action === 'refund')
      Payment refunded to client
    @else
      No financial movement (dispute closed)
    @endif
  </dd>

  <dt>Stripe reference</dt>
  <dd><code>{{ $stripeReference }}</code></dd>

  <dt>Executed at</dt>
  <dd>{{ $dispute->resolution_executed_at?->format('d M Y, H:i') }} UTC</dd>
</dl>

@if($action === 'transfer')
<p>Released funds will arrive in the freelancer's payout account within 1–2 business days depending on their bank.</p>
@elseif($action === 'refund')
<p>The refund will appear on the client's original payment method within 5–10 business days depending on their bank.</p>
@endif

<a href="{{ config('app.url') }}/disputes/{{ $dispute->id }}" class="btn">View dispute details</a>
@endsection
