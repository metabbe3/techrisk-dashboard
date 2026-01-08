<script>
    // Livewire upload error
    window.addEventListener('livewire:upload-error', (event) => {
        new FilamentNotification()
            .title('Upload failed')
            .body('The file upload failed. Please try again.')
            .danger()
            .send();
    });

    // General Livewire errors
    document.addEventListener('livewire:init', () => {
        Livewire.onError((error) => {
            new FilamentNotification()
                .title('An error occurred')
                .body(error.message || 'An unexpected error occurred. Please try again.')
                .danger()
                .send();
            return false; // Stop default Livewire error handling
        });
    });

    // General JavaScript errors
    window.onerror = function (message, source, lineno, colno, error) {
        new FilamentNotification()
            .title('A JavaScript error occurred')
            .body(message)
            .danger()
            .send();
    };
</script>