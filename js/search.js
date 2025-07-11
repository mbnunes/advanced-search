document.addEventListener('DOMContentLoaded', function() {
    const searchBtn = document.getElementById('search-btn');
    const resultsDiv = document.getElementById('search-results');
    
    searchBtn.addEventListener('click', function() {
        const filename = document.getElementById('filename').value;
        const tagsInput = document.getElementById('tags').value;
        const tagOperator = document.querySelector('input[name="tagOperator"]:checked').value;
        
        const tags = tagsInput ? tagsInput.split(',').map(tag => tag.trim()) : [];
        
        // Mostrar loading
        resultsDiv.innerHTML = '<div class="loading">Buscando...</div>';
        
        // URL corrigida
        fetch(OC.generateUrl('/apps/advancedsearch/api/search'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({
                filename: filename,
                tags: tags,
                tagOperator: tagOperator
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayResults(data.files);
            } else {
                resultsDiv.innerHTML = '<p class="error">Erro: ' + data.message + '</p>';
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            resultsDiv.innerHTML = '<p class="error">Erro na busca</p>';
        });
    });
    
    function displayResults(files) {
        if (files.length === 0) {
            resultsDiv.innerHTML = '<div class="no-results">Nenhum arquivo encontrado</div>';
            return;
        }
        
        let html = '<h3>Resultados (' + files.length + '):</h3><ul>';
        
        files.forEach(file => {
            const tags = file.tags.map(tag => `<span class="tag">${tag.name}</span>`).join(' ');
            const fileSize = formatFileSize(file.size);
            const fileDate = new Date(file.mtime * 1000).toLocaleDateString();
            
            html += `
                <li>
                    <strong>${file.name}</strong><br>
                    <small>Caminho: ${file.path}</small><br>
                    <small>Tamanho: ${fileSize} | Data: ${fileDate}</small><br>
                    <small>Tags: ${tags || 'Nenhuma'}</small>
                </li>
            `;
        });
        
        html += '</ul>';
        resultsDiv.innerHTML = html;
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
});