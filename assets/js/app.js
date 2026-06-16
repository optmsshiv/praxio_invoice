/**
 * Main Application JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize application
    console.log('Application loaded');
    
    // TODO: Add your application scripts
});

// API helper function
async function apiCall(endpoint, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    if (data && (method === 'POST' || method === 'PUT')) {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch('/api/' + endpoint, options);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}
