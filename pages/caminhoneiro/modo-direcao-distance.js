// Função para calcular distância inicial aproximada
function calcularDistanciaInicial() {
    if (!currentPosition) {
        // Se a localização atual ainda não estiver disponível, tentar novamente após um atraso
        setTimeout(calcularDistanciaInicial, 1000);
        return;
    }
    
    try {
        const origemLatEl = document.getElementById('origem_lat');
        const origemLngEl = document.getElementById('origem_lng');
        const destinoLatEl = document.getElementById('destino_lat');
        const destinoLngEl = document.getElementById('destino_lng');
        
        // Verificar se todos os elementos existem
        if (!origemLatEl || !origemLngEl || !destinoLatEl || !destinoLngEl) {
            console.error('Elementos de coordenadas não encontrados');
            return;
        }
        
        const origem = { 
            lat: parseFloat(origemLatEl.value), 
            lng: parseFloat(origemLngEl.value)
        };
        
        const destino = { 
            lat: parseFloat(destinoLatEl.value), 
            lng: parseFloat(destinoLngEl.value)
        };
        
        // Verificar se as coordenadas são válidas
        if (isNaN(origem.lat) || isNaN(origem.lng) || isNaN(destino.lat) || isNaN(destino.lng)) {
            console.error('Coordenadas inválidas');
            return;
        }
        
        // Determinar o destino atual com base na etapa
        const destinoAtual = etapaViagem === 'coleta' ? origem : destino;
        
        let distancia;
        
        // Usar a biblioteca de geometria do Google Maps se disponível
        if (typeof google !== 'undefined' && google.maps && google.maps.geometry) {
            try {
                const from = new google.maps.LatLng(currentPosition.lat, currentPosition.lng);
                const to = new google.maps.LatLng(destinoAtual.lat, destinoAtual.lng);
                
                // Calcular a distância em metros e converter para km
                distancia = google.maps.geometry.spherical.computeDistanceBetween(from, to) / 1000;
            } catch (geoError) {
                console.warn('Erro ao usar geometria do Google Maps:', geoError);
                // Fallback para o cálculo manual
                distancia = calcDistanceKm(
                    currentPosition.lat, 
                    currentPosition.lng, 
                    destinoAtual.lat, 
                    destinoAtual.lng
                );
            }
        } else {
            // Função para calcular distância aproximada em quilômetros (fórmula de Haversine)
            distancia = calcDistanceKm(
                currentPosition.lat, 
                currentPosition.lng, 
                destinoAtual.lat, 
                destinoAtual.lng
            );
        }
        
        // Atualizar a distância no painel de navegação
        const elementoDistancia = document.getElementById('nav-distance');
        if (elementoDistancia) {
            elementoDistancia.textContent = Math.round(distancia) + ' km';
        }
        
        // Calcular tempo estimado (velocidade média de 70 km/h)
        const tempoEstimado = distancia / 70;
        const horas = Math.floor(tempoEstimado);
        const minutos = Math.round((tempoEstimado - horas) * 60);
        
        // Atualizar o tempo no painel de navegação
        const elementoTempo = document.getElementById('nav-time');
        if (elementoTempo) {
            elementoTempo.textContent = horas + 'h ' + minutos + 'min';
        }
    } catch (error) {
        console.error('Erro ao calcular distância:', error);
    }
}

// Função para calcular distância aproximada em quilômetros (fórmula de Haversine)
function calcDistanceKm(lat1, lon1, lat2, lon2) {
    const R = 6371; // Raio da Terra em km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = 
        Math.sin(dLat/2) * Math.sin(dLat/2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
        Math.sin(dLon/2) * Math.sin(dLon/2); 
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); 
    const distance = R * c;
    return distance;
} 