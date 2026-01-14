<script>
    // Configure Livewire to send credentials with requests
    document.addEventListener('livewire:init', () => {
        if (typeof Livewire === 'object' && Livewire.fetch) {
            // Ensure credentials (cookies) are sent with Livewire requests
            const originalFetch = window.fetch;
            window.fetch = function(...args) {
                if (args[0] && args[0].toString().includes('livewire/update')) {
                    args[1] = args[1] || {};
                    args[1].credentials = 'include';
                }
                return originalFetch.apply(this, args);
            };
        }
    });

    // Livewire upload error
    window.addEventListener('livewire:upload-error', (event) => {
        new FilamentNotification()
            .title('Upload failed')
            .body('The file upload failed. Please try again. If the problem persists, contact support.')
            .danger()
            .send();
    });

    // General Livewire errors
    document.addEventListener('livewire:init', () => {
        if (typeof Livewire.onError === 'function') {
            Livewire.onError((error) => {
                new FilamentNotification()
                    .title('An error occurred')
                    .body(error.message || 'An unexpected error occurred. Please try again.')
                    .danger()
                    .send();
                return false; // Stop default Livewire error handling
            });
        }
    });

    // // General JavaScript errors
    // window.onerror = function (message, source, lineno, colno, error) {
    //     new FilamentNotification()
    //         .title('A JavaScript error occurred')
    //         .body(message)
    //         .danger()
    //         .send();
    // };
</script>