@extends('emails._layout')

@section('subject', 'Dispute resolved')

@section('content')
<h1>Your dispute has been resolved</h1>
<p>Hello {{ $recipient->name }},</p>

@php
  $outcomeLabel = match($dispute->status) {
    'resolved_client'     => 'in favour of the client',
    'resolved_freelancer' => 'in favour of the freelancer',
    'resolved_split'      => 'as a split resolution',
    default               => 'and closed',
  };
@endphp

<p>The mediator has reviewed the evidence and resolved this dispute {{ $outcomeLabel }}.</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $dispute->contract->title }}</dd>
  <dt>Resolution outcome</dt>
  <dd>{{ ucfirst(str_replace('_', ' ', $dispute->status)) }}</dd>
  <dt>Resolution notes</dt>
  <dd>{{ $dispute->resolution_notes }}</dd>
  <dt>Resolved</dt>
  <dd>{{ $dispute->resolved_at?->format('d M Y, H:i') }} UTC</dd>
  @if($dispute->mediator)
  <dt>Resolved by</dt>
  <dd>{{ $dispute->mediator->name }}</dd>
  @endif
</dl>

<p>Escrow funds will be disbursed according to the resolution above. Any released amounts will reach your payout account within 1–2 business days.</p>

<a href="{{ config('app.url') }}/disputes/{{ $dispute->id }}" class="btn">View resolution</a>
@endsection
