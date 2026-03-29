// Global date/time formatting function
// Format: DD/MM/YYYY, HH:MM:SS AM/PM
function formatDateTime(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();

    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';

    hours = hours % 12;
    hours = hours ? hours : 12; // 0 should be 12
    hours = String(hours).padStart(2, '0');

    return `${day}/${month}/${year}, ${hours}:${minutes}:${seconds} ${ampm}`;
}

// Format time only: HH:MM:SS AM/PM
function formatTime(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);

    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';

    hours = hours % 12;
    hours = hours ? hours : 12;
    hours = String(hours).padStart(2, '0');

    return `${hours}:${minutes}:${seconds} ${ampm}`;
}
