@extends('layouts.admin')

@section('title', 'Tableau de bord Super-Admin')

@section('content')
    <livewire:admin.platform-dashboard :section="$section ?? 'overview'" />
@endsection
