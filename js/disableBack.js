for (let i = 0; i < 100; i++) {
    window.history.pushState(null, null, window.location.href);
}

function disableBackButton() {
    // Prevent the back button and show an error message
    window.onpopstate = function () {
        alert("Going back is not allowed on this page.");
        window.history.pushState(null, null, window.location.href); // Re-push the state to prevent back
    };
}
