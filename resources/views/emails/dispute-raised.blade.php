@extends('emails._layout')

@section('subject', 'Dispute opened')

@section('content')
<h1>A dispute has been opened</h1>
<p>Hello {{ $recipient->name }},</p>
<p>A dispute has been raised on a milestone in your contract. Escrow funds for that milestone are now frozen pending resolution.</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $dispute->contract->title }}</dd>
  @if($dispute->milestone)
  <dt>Milestone</dt>
  <dd>{{ $dispute->milestone->title }}</dd>
  <dt>Amount frozen</dt>
  <dd>${{ number_format($dispute->milestone->amount, 2) }}</dd>
  @endif
  <dt>Reason given</dt>
  <dd>{{ $dispute->reason }}</dd>
  <dt>Opened by</dt>
  <dd>{{ $dispute->raisedBy->name }}</dd>
  <dt>Opened</dt>
  <dd>{{ $dispute->created_at->format('d M Y, H:i') }} UTC</dd>
</dl>

<p>You have 5 business days to submit your evidence. A mediator will review both submissions and issue a written resolution.</p>

<a href="{{ config('app.url') }}/disputes/{{ $dispute->id }}" class="btn">View dispute &amp; submit evidence</a>

<p class="note">Funds remain frozen until the mediator closes the case. Neither party can release or withdraw frozen funds.</p>
@endsection
