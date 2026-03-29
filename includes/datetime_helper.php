<?php
// Global date/time formatting functions
// Format: DD/MM/YYYY, HH:MM:SS AM/PM

function formatDateTime($dateString)
{
    if (empty($dateString))
        return '-';
    $timestamp = strtotime($dateString);
    if ($timestamp === false)
        return '-';
    return date('d/m/Y, h:i:s A', $timestamp);
}

function formatTime($dateString)
{
    if (empty($dateString))
        return '-';
    $timestamp = strtotime($dateString);
    if ($timestamp === false)
        return '-';
    return date('h:i:s A', $timestamp);
}
