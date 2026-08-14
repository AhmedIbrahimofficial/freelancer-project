@extends('emails._layout')

@section('subject', 'Contract fully signed')

@section('content')
<h1>Both parties have signed</h1>
<p>Hello {{ $recipient->name }},</p>
<p>The contract below is now binding — both typed-name signatures have been recorded.</p>

<dl class="meta">
  <dt>Contract</dt>
  <dd>{{ $contract->title }}</dd>
  <dt>Value</dt>
  <dd>${{ number_format($contract->total_amount, 2) }} {{ $contract->currency }}</dd>
  <dt>Client</dt>
  <dd>{{ $contract->client->name }}</dd>
  <dt>Freelancer</dt>
  <dd>{{ $contract->freelancer->name }}</dd>
  <dt>Signed</dt>
  <dd>{{ now()->format('d M Y, H:i') }} UTC</dd>
</dl>

<p>The next step is for the client to fund the escrow. Work should not begin until funds are confirmed held.</p>

<a href="{{ config('app.url') }}/contracts/{{ $contract->id }}" class="btn">View contract</a>
@endsection
