@extends('errors.layout')

@section('title', 'Too many requests')
@section('code', '429')
@section('heading', 'Slow down a little')
@section('message', 'You are sending requests too quickly. Please wait a moment and try again.')
