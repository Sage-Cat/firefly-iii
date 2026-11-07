@extends('layout.v3.session')
@php($title = 'Курси НБУ')
@section('content')
    @include('exchange-rates.nbu-today')
@endsection
