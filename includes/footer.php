<?php
/**
 * YAKAP GAMOT SYSTEM - Footer
 */
?>
</div><!-- .content-area -->

</div><!-- .main-content -->

<button id="back-to-top" class="back-to-top" type="button" aria-label="Back to top">↑</button>

<script>
// Sidebar toggle for mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

// Auto-dismiss flash messages
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        var flashes = document.querySelectorAll('.flash-message');
        flashes.forEach(function(f) {
            f.style.transition = 'opacity 0.3s';
            f.style.opacity = '0';
            setTimeout(function() { f.remove(); }, 300);
        });
    }, 4000);
});
</script>
<script src="js/script.js"></script>
</body>
</html>
