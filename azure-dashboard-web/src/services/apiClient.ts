import axios from 'axios';

export const apiClient = axios.create({
    //baseURL: 'http://localhost:8001/api',
    baseURL: 'http://100.112.88.114:8001/api',
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
    }
});