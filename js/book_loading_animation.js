/**
 * SMOOTH BOOK LOADING ANIMATION SYSTEM
 * Universal JavaScript for animating book displays across all pages
 * 
 * Features:
 * - Multiple animation styles (fadeInUp, scale, slide, bounce, rotate, flip)
 * - Staggered animations for sequential loading
 * - Image lazy loading with smooth transitions
 * - Skeleton loaders
 * - Performance optimized with Intersection Observer
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        animationStyle: 'fadeInUp', // Options: fadeInUp, scale, slideLeft, slideRight, bounce, rotate, flip
        staggerDelay: 50, // milliseconds between each item
        enableLazyLoad: true,
        enableSkeletonLoader: true,
        observerThreshold: 0.1,
        observerRootMargin: '50px'
    };

    /**
     * Initialize book loading animations
     */
    function initBookAnimations() {
        // Apply animations to all book elements
        animateBooks();
        
        // Setup lazy loading for book images
        if (CONFIG.enableLazyLoad) {
            setupLazyLoading();
        }
        
        // Setup intersection observer for scroll animations
        setupScrollAnimations();
        
        // Listen for dynamic content
        observeDynamicContent();
    }

    /**
     * Apply animation classes to book elements
     */
    function animateBooks() {
        const selectors = [
            '.book-item',
            '.book-card',
            'tr[data-book-id]',
            '.top-books-list .book-item',
            '[class*="book-"]'
        ];

        selectors.forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach((element, index) => {
                if (!element.classList.contains('book-loading')) {
                    // Add animation class based on config
                    const animationClass = getAnimationClass(CONFIG.animationStyle);
                    element.classList.add(animationClass);
                    
                    // Set staggered delay
                    element.style.animationDelay = `${index * CONFIG.staggerDelay}ms`;
                }
            });
        });
    }

    /**
     * Get animation class based on style
     */
    function getAnimationClass(style) {
        const classMap = {
            'fadeInUp': 'book-loading',
            'scale': 'book-loading-scale',
            'slideLeft': 'book-loading-slide-left',
            'slideRight': 'book-loading-slide-right',
            'bounce': 'book-loading-bounce',
            'rotate': 'book-loading-rotate',
            'flip': 'book-loading-flip'
        };
        return classMap[style] || 'book-loading';
    }

    /**
     * Setup lazy loading for book cover images
     */
    function setupLazyLoading() {
        const images = document.querySelectorAll('img[data-src], .book-cover, .book-cover-small');
        
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        loadImage(img);
                        observer.unobserve(img);
                    }
                });
            }, {
                rootMargin: CONFIG.observerRootMargin,
                threshold: CONFIG.observerThreshold
            });

            images.forEach(img => {
                img.classList.add('book-cover-loading');
                imageObserver.observe(img);
            });
        } else {
            // Fallback for browsers without IntersectionObserver
            images.forEach(img => loadImage(img));
        }
    }

    /**
     * Load image with smooth transition
     */
    function loadImage(img) {
        const src = img.dataset.src || img.src;
        
        if (!src) return;

        const tempImg = new Image();
        tempImg.onload = function() {
            img.src = src;
            img.classList.remove('book-cover-loading');
            img.classList.add('book-cover-loaded');
            
            // Remove data-src after loading
            if (img.dataset.src) {
                delete img.dataset.src;
            }
        };
        
        tempImg.onerror = function() {
            img.classList.remove('book-cover-loading');
            // Set placeholder image on error
            img.src = '../PICTURES/bookstore.png';
        };
        
        tempImg.src = src;
    }

    /**
     * Setup scroll-triggered animations
     */
    function setupScrollAnimations() {
        if (!('IntersectionObserver' in window)) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('book-loading');
                }
            });
        }, {
            rootMargin: CONFIG.observerRootMargin,
            threshold: CONFIG.observerThreshold
        });

        // Observe elements that should animate on scroll
        const scrollElements = document.querySelectorAll('.book-item, .book-card, .content-card');
        scrollElements.forEach(el => {
            if (!el.classList.contains('book-loading')) {
                observer.observe(el);
            }
        });
    }

    /**
     * Observe DOM for dynamically added content
     */
    function observeDynamicContent() {
        if (!('MutationObserver' in window)) return;

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) { // Element node
                        // Check if the added node is a book element
                        if (isBookElement(node)) {
                            animateElement(node);
                        }
                        
                        // Check for book elements within the added node
                        const bookElements = node.querySelectorAll && node.querySelectorAll('.book-item, .book-card, [data-book-id]');
                        if (bookElements) {
                            bookElements.forEach((el, index) => {
                                setTimeout(() => animateElement(el), index * CONFIG.staggerDelay);
                            });
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    /**
     * Check if element is a book element
     */
    function isBookElement(element) {
        return element.classList.contains('book-item') ||
               element.classList.contains('book-card') ||
               element.hasAttribute('data-book-id') ||
               element.tagName === 'TR' && element.closest('.table-card');
    }

    /**
     * Animate a single element
     */
    function animateElement(element) {
        const animationClass = getAnimationClass(CONFIG.animationStyle);
        element.classList.add(animationClass);
        
        // Setup lazy loading for images within the element
        if (CONFIG.enableLazyLoad) {
            const images = element.querySelectorAll('img');
            images.forEach(img => {
                img.classList.add('book-cover-loading');
                loadImage(img);
            });
        }
    }

    /**
     * Create skeleton loader
     */
    function createSkeletonLoader(container, count = 5) {
        if (!CONFIG.enableSkeletonLoader) return;

        const skeletonHTML = `
            <div class="book-skeleton-card">
                <div class="book-skeleton-image"></div>
                <div class="book-skeleton-title"></div>
                <div class="book-skeleton-author"></div>
                <div class="book-skeleton-price"></div>
            </div>
        `;

        const fragment = document.createDocumentFragment();
        for (let i = 0; i < count; i++) {
            const div = document.createElement('div');
            div.innerHTML = skeletonHTML;
            fragment.appendChild(div.firstElementChild);
        }

        container.innerHTML = '';
        container.appendChild(fragment);
    }

    /**
     * Remove skeleton loaders
     */
    function removeSkeletonLoaders(container) {
        const skeletons = container.querySelectorAll('.book-skeleton-card');
        skeletons.forEach(skeleton => {
            skeleton.style.animation = 'fadeOut 0.3s ease-out';
            setTimeout(() => skeleton.remove(), 300);
        });
    }

    /**
     * Show loading spinner
     */
    function showLoadingSpinner(container) {
        const spinner = document.createElement('div');
        spinner.className = 'books-loading-spinner';
        spinner.innerHTML = '<div class="spinner-book"></div>';
        container.appendChild(spinner);
    }

    /**
     * Hide loading spinner
     */
    function hideLoadingSpinner(container) {
        const spinner = container.querySelector('.books-loading-spinner');
        if (spinner) {
            spinner.style.animation = 'fadeOut 0.3s ease-out';
            setTimeout(() => spinner.remove(), 300);
        }
    }

    /**
     * Animate book removal
     */
    function animateBookRemoval(element, callback) {
        element.classList.add('book-removing');
        setTimeout(() => {
            if (callback) callback();
            element.remove();
        }, 300);
    }

    /**
     * Animate book addition success
     */
    function animateBookSuccess(element) {
        element.classList.add('book-added-success');
        setTimeout(() => {
            element.classList.remove('book-added-success');
        }, 600);
    }

    /**
     * Refresh animations (useful after AJAX updates)
     */
    function refreshAnimations() {
        // Remove existing animation classes
        const elements = document.querySelectorAll('[class*="book-loading"]');
        elements.forEach(el => {
            el.style.animation = 'none';
            el.offsetHeight; // Trigger reflow
            el.style.animation = null;
        });

        // Reapply animations
        animateBooks();
        
        if (CONFIG.enableLazyLoad) {
            setupLazyLoading();
        }
    }

    /**
     * Change animation style dynamically
     */
    function setAnimationStyle(style) {
        if (['fadeInUp', 'scale', 'slideLeft', 'slideRight', 'bounce', 'rotate', 'flip'].includes(style)) {
            CONFIG.animationStyle = style;
            refreshAnimations();
        }
    }

    /**
     * Public API
     */
    window.BookLoadingAnimation = {
        init: initBookAnimations,
        refresh: refreshAnimations,
        setStyle: setAnimationStyle,
        createSkeleton: createSkeletonLoader,
        removeSkeleton: removeSkeletonLoaders,
        showSpinner: showLoadingSpinner,
        hideSpinner: hideLoadingSpinner,
        animateRemoval: animateBookRemoval,
        animateSuccess: animateBookSuccess,
        config: CONFIG
    };

    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBookAnimations);
    } else {
        initBookAnimations();
    }

    // Re-initialize on page show (for back/forward cache)
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            refreshAnimations();
        }
    });

})();

/**
 * USAGE EXAMPLES:
 * 
 * 1. Basic initialization (automatic):
 *    // Animations start automatically on page load
 * 
 * 2. Change animation style:
 *    BookLoadingAnimation.setStyle('bounce');
 * 
 * 3. Refresh after AJAX update:
 *    BookLoadingAnimation.refresh();
 * 
 * 4. Show skeleton loader:
 *    BookLoadingAnimation.createSkeleton(container, 5);
 * 
 * 5. Remove skeleton loader:
 *    BookLoadingAnimation.removeSkeleton(container);
 * 
 * 6. Show loading spinner:
 *    BookLoadingAnimation.showSpinner(container);
 * 
 * 7. Animate book removal:
 *    BookLoadingAnimation.animateRemoval(element, () => {
 *        // Callback after removal
 *    });
 * 
 * 8. Animate success:
 *    BookLoadingAnimation.animateSuccess(element);
 * 
 * 9. Configure settings:
 *    BookLoadingAnimation.config.staggerDelay = 100;
 *    BookLoadingAnimation.config.animationStyle = 'scale';
 */
