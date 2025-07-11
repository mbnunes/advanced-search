document.addEventListener('DOMContentLoaded', function() {
    const searchBtn = document.getElementById('search-btn');
    const clearBtn = document.getElementById('clear-btn');
    const fileList = document.getElementById('fileList');
    const emptyContent = document.getElementById('emptycontent');
    const loading = document.getElementById('loading');
    const resultCount = document.getElementById('result-count');
    const viewListBtn = document.getElementById('view-list');
    const viewGridBtn = document.getElementById('view-grid');
    
    let currentView = 'list';
    
    // Event listeners
    searchBtn.addEventListener('click', performSearch);
    clearBtn.addEventListener('click', clearSearch);
    viewListBtn.addEventListener('click', () => setView('list'));
    viewGridBtn.addEventListener('click', () => setView('grid'));
    
    // Busca ao pressionar Enter
    document.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            performSearch();
        }
    });
    
    function performSearch() {
        const filename = document.getElementById('filename').value;
        const tagsInput = document.getElementById('tags').value;
        const tagOperator = document.querySelector('input[name="tagOperator"]:checked').value;
        const fileType = document.getElementById('file-type').value;
        
        const tags = tagsInput ? tagsInput.split(',').map(tag => tag.trim()) : [];
        
        // Mostrar loading
        showLoading();
        
        fetch(OC.generateUrl('/apps/advancedsearch/api/search'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({
                filename: filename,
                tags: tags,
                tagOperator: tagOperator,
                fileType: fileType
            })
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                displayResults(data.files);
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Erro:', error);
            showError('Erro na busca');
        });
    }
    
    function clearSearch() {
        document.getElementById('filename').value = '';
        document.getElementById('tags').value = '';
        document.getElementById('file-type').value = '';
        document.getElementById('tag-and').checked = true;
        
        fileList.innerHTML = '';
        resultCount.textContent = '';
        showEmptyContent();
    }
    
    function displayResults(files) {
        hideEmptyContent();
        
        if (files.length === 0) {
            showEmptyContent();
            resultCount.textContent = 'Nenhum resultado encontrado';
            return;
        }
        
        resultCount.textContent = `${files.length} arquivo${files.length !== 1 ? 's' : ''} encontrado${files.length !== 1 ? 's' : ''}`;
        
        let html = '';
        
        files.forEach(file => {
            const tags = file.tags.map(tag => `<span class="tag">${tag.name}</span>`).join(' ');
            const fileSize = formatFileSize(file.size);
            const fileDate = new Date(file.mtime * 1000).toLocaleDateString();
            const fileIcon = getFileIcon(file.name);
            
            html += `
                <tr class="file-row" data-file-id="${file.id}">
                    <td class="filename">
                        <div style="display: flex; align-items: center;">
                            <div class="file-icon ${fileIcon}"></div>
                            <div>
                                <div class="file-name">${escapeHtml(file.name)}</div>
                                <div class="file-path">${escapeHtml(file.path)}</div>
                            </div>
                        </div>
                    </td>
                    <td class="filesize">
                        <span class="file-size">${fileSize}</span>
                    </td>
                    <td class="date">
                        <span class="file-date">${fileDate}</span>
                    </td>
                    <td class="tags">
                        <div class="file-tags">${tags || '<span style="color: var(--color-text-light);">Nenhuma</span>'}</div>
                    </td>
                </tr>
            `;
        });
        
        fileList.innerHTML = html;
        
        // Adicionar event listeners para as linhas
        document.querySelectorAll('.file-row').forEach(row => {
            row.addEventListener('click', function() {
                const fileId = this.getAttribute('data-file-id');
                openFile(fileId);
            });
        });
    }
    
    function getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        
        const iconMap = {
            'pdf': 'icon-filetype-pdf',
            'doc': 'icon-filetype-document',
            'docx': 'icon-filetype-document',
            'xls': 'icon-filetype-spreadsheet',
            'xlsx': 'icon-filetype-spreadsheet',
            'ppt': 'icon-filetype-presentation',
            'pptx': 'icon-filetype-presentation',
            'txt': 'icon-filetype-text',
            'jpg': 'icon-filetype-image',
            'jpeg': 'icon-filetype-image',
            'png': 'icon-filetype-image',
            'gif': 'icon-filetype-image',
            'mp4': 'icon-filetype-video',
            'avi': 'icon-filetype-video',
            'mp3': 'icon-filetype-audio',
            'wav': 'icon-filetype-audio',
            'zip': 'icon-filetype-archive',
            'rar': 'icon-filetype-archive'
        };
        
        return iconMap[ext] || 'icon-filetype-file';
    }
    
    function openFile(fileId) {
        // Implementar abertura do arquivo
        console.log('Opening file:', fileId);
    }
    
    function setView(view) {
        currentView = view;
        
        if (view === 'list') {
            viewListBtn.classList.add('active');
            viewGridBtn.classList.remove('active');
            // Implementar view de lista
        } else {
            viewGridBtn.classList.add('active');
            viewListBtn.classList.remove('active');
            // Implementar view de grid
        }
    }
    
    function showLoading() {
        loading.classList.remove('hidden');
        emptyContent.classList.add('hidden');
        fileList.innerHTML = '';
    }
    
    function hideLoading() {
        loading.classList.add('hidden');
    }
    
    function showEmptyContent() {
        emptyContent.classList.remove('hidden');
        fileList.innerHTML = '';
    }
    
    function hideEmptyContent() {
        emptyContent.classList.add('hidden');
    }
    
    function showError(message) {
        showEmptyContent();
        document.querySelector('#emptycontent h2').textContent = 'Erro';
        document.querySelector('#emptycontent p').textContent = message;
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Inicializar
    showEmptyContent();
});