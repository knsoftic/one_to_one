@extends('errors.layout')

@section('title', 'Access denied')
@section('code', '403')
@section('heading', 'Access denied')
@section('message', $exception?->getMessage() ?: "You don't have permission to view this page.")
