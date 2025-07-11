document.addEventListener('DOMContentLoaded', function() {
    const searchBtn = document.getElementById('search-btn');
    const resultsDiv = document.getElementById('search-results');
    
    searchBtn.addEventListener('click', function() {
        const filename = document.getElementById('filename').value;
        const tagsInput = document.getElementById('tags').value;
        const tagOperator = document.querySelector('input[name="tagOperator"]:checked').value;
        
        const tags = tagsInput ? tagsInput.split(',').map(tag => tag.trim()) : [];
        
        fetch(OC.generateUrl('/apps/advanced-search/search'), {
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
                resultsDiv.innerHTML = '<p>Erro: ' + data.message + '</p>';
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            resultsDiv.innerHTML = '<p>Erro na busca</p>';
        });
    });
    
    function displayResults(files) {
        if (files.length === 0) {
            resultsDiv.innerHTML = '<p>Nenhum arquivo encontrado</p>';
            return;
        }
        
        let html = '<h3>Resultados (' + files.length + '):</h3><ul>';
        
        files.forEach(file => {
            const tags = file.tags.map(tag => `<span class="tag">${tag.name}</span>`).join(' ');
            html += `
                <li>
                    <strong>${file.name}</strong><br>
                    <small>Caminho: ${file.path}</small><br>
                    <small>Tags: ${tags}</small>
                </li>
            `;
        });
        
        html += '</ul>';
        resultsDiv.innerHTML = html;
    }
});
