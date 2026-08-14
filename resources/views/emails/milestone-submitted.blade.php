@extends('emails._layout')

@section('subject', 'Milestone ready for review')

@section('content')
<h1>A milestone has been submitted for your review</h1>
<p>Hello {{ $recipient->name }},</p>
<p>{{ $milestone->contract->freelancer->name }} has submitted a milestone for review on your contract.</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $milestone->contract->title }}</dd>
  <dt>Milestone</dt>
  <dd>{{ $milestone->title }}</dd>
  <dt>Amount</dt>
  <dd>${{ number_format($milestone->amount, 2) }} {{ $milestone->contract->currency }}</dd>
  <dt>Submitted</dt>
  <dd>{{ $milestone->submitted_at?->format('d M Y, H:i') }} UTC</dd>
  @if($milestone->submission_notes)
  <dt>Notes from freelancer</dt>
  <dd>{{ $milestone->submission_notes }}</dd>
  @endif
</dl>

<p>You have 5 business days to approve, request changes, or open a dispute. Funds remain held in escrow until you act.</p>

<a href="{{ config('app.url') }}/contracts/{{ $milestone->contract_id }}" class="btn">Review milestone</a>
@endsection
