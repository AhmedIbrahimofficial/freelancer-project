@extends('emails._layout')

@section('subject', 'Signature requested')

@section('content')
<h1>You have been asked to sign a contract</h1>
<p>{{ $requestedBy->name }} has sent you a milestone contract for review and signature.</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $contract->title }}</dd>
  <dt>Value</dt>
  <dd>${{ number_format($contract->total_amount, 2) }} {{ $contract->currency }}</dd>
  <dt>Milestones</dt>
  <dd>{{ $contract->milestones->count() }} milestone{{ $contract->milestones->count() !== 1 ? 's' : '' }}</dd>
  @if($contract->start_date)
  <dt>Start date</dt>
  <dd>{{ $contract->start_date->format('d M Y') }}</dd>
  @endif
</dl>

<p>Review the scope, milestones, and payment terms before signing. The contract only becomes binding once both parties have signed.</p>

<a href="{{ config('app.url') }}/contracts/{{ $contract->id }}" class="btn">Review &amp; sign</a>

<p class="note">You are not obligated to sign. If the terms are not right, discuss changes with {{ $requestedBy->name }} before proceeding.</p>
@endsection
