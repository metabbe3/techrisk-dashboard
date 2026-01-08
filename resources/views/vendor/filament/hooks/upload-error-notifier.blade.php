<script>
    window.addEventListener('livewire:upload-error', (event) => {
        new FilamentNotification()
            .title('Upload failed')
            .body('The file upload failed. Please try again. If the problem persists, contact support.')
            .danger()
            .send();
    });
</script>
