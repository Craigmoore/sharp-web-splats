# Docker Setup for SHARP Web Splats

This guide explains how to run SHARP Web Splats in Docker or Docker Compose, both on WSL and other platforms.

## Prerequisites

- **Docker**: Version 20.10 or later
- **Docker Compose**: Version 2.0 or later (included with Docker Desktop)
- **For GPU support**: NVIDIA Container Toolkit (optional but recommended)
- **Git submodules initialized**: Run `git submodule update --init --recursive` before building

## Quick Start

### 1. Initialize Git Submodules

Before building the Docker image, you **must** initialize the ml-sharp submodule:

```bash
git submodule update --init --recursive
```

### 2. Choose Your Mode

#### CPU Mode (Default)
```bash
docker-compose up sharp-web-splats
```

#### GPU Mode (Recommended for better performance)
```bash
docker-compose up sharp-web-splats-gpu
```

The application will be available at:
- **HTTPS**: `https://localhost:8080` (self-signed certificate, accept browser warning)
- **Health check**: `https://localhost:8080/health`

## GPU Support Setup

### Installing NVIDIA Container Toolkit (WSL/Linux)

1. **Add NVIDIA package repository:**
```bash
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | \
    sudo tee /etc/apt/sources.list.d/nvidia-docker.list
```

2. **Install NVIDIA Container Toolkit:**
```bash
sudo apt-get update
sudo apt-get install -y nvidia-container-toolkit
```

3. **Configure Docker daemon:**
```bash
sudo nvidia-ctk runtime configure --runtime=docker
sudo systemctl restart docker
```

4. **Test GPU access:**
```bash
docker run --rm --gpus all nvidia/cuda:12.8.0-base-ubuntu22.04 nvidia-smi
```

### Verify GPU in Container

After starting the GPU service, check the health endpoint:
```bash
curl -k https://localhost:8080/health
```

You should see:
```json
{
  "status": "ok",
  "device": "cuda",
  "model_loaded": true
}
```

If `device` shows `"cpu"` instead of `"cuda"`, GPU support is not working.

## Environment Variables

You can customize the application behavior with environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `HOST` | `0.0.0.0` | Server bind address |
| `PORT` | `8080` | Server port |
| `USE_SSL` | `true` | Enable HTTPS with self-signed certificate |
| `FORCE_CPU` | `false` | Force CPU mode even if GPU is available |

### Example: Custom Configuration

Edit `docker-compose.yml`:
```yaml
environment:
  - HOST=0.0.0.0
  - PORT=8080
  - USE_SSL=false  # Disable SSL for internal networks
  - FORCE_CPU=true  # Force CPU mode
```

Or use a `.env` file:
```bash
# .env
HOST=0.0.0.0
PORT=8080
USE_SSL=false
FORCE_CPU=false
```

## Volume Mounts

The Docker Compose configuration includes several volume mounts:

### 1. Model Cache (Persistent)
```yaml
- model-cache:/root/.cache/torch/hub/checkpoints
```
- **Purpose**: Persists the downloaded SHARP model (~multi-GB)
- **Benefit**: Avoids re-downloading on container restart
- **Location**: Named Docker volume

### 2. Static Files (Bind Mount)
```yaml
- ./static:/app/static
```
- **Purpose**: Generated PLY/SOG files accessible from host
- **Benefit**: Easy access to generated splats
- **Location**: Local `./static` directory

### 3. Samples Directory (Bind Mount)
```yaml
- ./static/samples:/app/static/samples
```
- **Purpose**: Pre-generated sample splats
- **Benefit**: Add samples by placing files in local directory
- **Location**: Local `./static/samples` directory

## Building the Image

### Build CPU Version
```bash
docker-compose build sharp-web-splats
```

### Build GPU Version
```bash
docker-compose build sharp-web-splats-gpu
```

### Build with Custom Tag
```bash
docker build -t sharp-web-splats:latest .
```

## Running Without Docker Compose

### CPU Mode
```bash
docker run -d \
  --name sharp-web-splats \
  -p 8080:8080 \
  -v sharp-model-cache:/root/.cache/torch/hub/checkpoints \
  -v $(pwd)/static:/app/static \
  sharp-web-splats:latest
```

### GPU Mode
```bash
docker run -d \
  --name sharp-web-splats \
  --gpus all \
  -p 8080:8080 \
  -v sharp-model-cache:/root/.cache/torch/hub/checkpoints \
  -v $(pwd)/static:/app/static \
  sharp-web-splats:latest
```

## Troubleshooting

### Issue: Container exits immediately

**Check logs:**
```bash
docker-compose logs sharp-web-splats
```

**Common causes:**
1. Git submodule not initialized → Run `git submodule update --init --recursive`
2. Port 8080 already in use → Change `PORT` environment variable
3. Model download failed → Check network connection

### Issue: GPU not detected

**Verify NVIDIA Container Toolkit:**
```bash
docker run --rm --gpus all nvidia/cuda:12.8.0-base-ubuntu22.04 nvidia-smi
```

**Check Docker Compose GPU configuration:**
Ensure you're using `sharp-web-splats-gpu` service, not `sharp-web-splats`.

**Force GPU in health check:**
```bash
curl -k https://localhost:8080/health | jq .device
```
Should return `"cuda"`, not `"cpu"`.

### Issue: SSL certificate warnings

**Expected behavior:**
The application uses self-signed certificates for HTTPS (required for WebXR). Browsers will show warnings.

**Accept the warning:**
- Chrome: Click "Advanced" → "Proceed to localhost (unsafe)"
- Firefox: Click "Advanced" → "Accept the Risk and Continue"

**Disable SSL (not recommended for VR/AR):**
Set `USE_SSL=false` in `docker-compose.yml`.

### Issue: Model download is slow on first run

**Expected behavior:**
The SHARP model downloads from Apple's CDN on first run (~multi-GB). This can take several minutes.

**Monitor download progress:**
```bash
docker-compose logs -f sharp-web-splats
```

**Pre-download during build (advanced):**
Add to Dockerfile before `CMD`:
```dockerfile
RUN python -c "import torch; torch.hub.load_state_dict_from_url('https://ml-site.cdn-apple.com/models/sharp/sharp_2572gikvuh.pt')"
```

### Issue: "splat-transform not found" warning

**Expected behavior:**
The Dockerfile installs `splat-transform` globally. If you see this warning, compression will fail but the app will serve uncompressed PLY files.

**Verify installation:**
```bash
docker exec -it sharp-web-splats npm list -g @playcanvas/splat-transform
```

**Reinstall if needed:**
```bash
docker exec -it sharp-web-splats npm install -g @playcanvas/splat-transform
```

### Issue: Permission denied for static files

**On Linux/WSL:**
Ensure the `static` directory is writable:
```bash
chmod -R 777 static/
```

## Performance Optimization

### CPU Performance
- **Multi-threading**: PyTorch automatically uses multiple CPU cores
- **Reduce resolution**: Modify `internal_shape` in app.py (default: 1536x1536)
- **Batch processing**: Process multiple images sequentially

### GPU Performance
- **VRAM requirements**: ~4GB for model + inference
- **Batch size**: Default is 1 image at a time
- **CUDA optimization**: PyTorch automatically optimizes for your GPU

### Docker Performance
- **Limit resources**: Add to docker-compose.yml:
```yaml
deploy:
  resources:
    limits:
      cpus: '4'
      memory: 8G
```

## Production Deployment

### 1. Use a Reverse Proxy
Place nginx or Traefik in front for:
- Real SSL certificates (Let's Encrypt)
- Rate limiting
- Load balancing
- Static file serving

### 2. Disable Debug Mode
Flask debug mode is already disabled in production.

### 3. Use Production WSGI Server
Consider using Gunicorn instead of Flask's development server:

**Add to requirements.txt:**
```
gunicorn
```

**Update CMD in Dockerfile:**
```dockerfile
CMD ["gunicorn", "-w", "4", "-b", "0.0.0.0:8080", "--certfile=/tmp/cert.pem", "--keyfile=/tmp/key.pem", "app:app"]
```

### 4. Monitor Resources
Use Docker stats:
```bash
docker stats sharp-web-splats
```

## Stopping and Cleaning Up

### Stop containers
```bash
docker-compose down
```

### Remove volumes (deletes cached model!)
```bash
docker-compose down -v
```

### Remove images
```bash
docker rmi sharp-web-splats:latest
```

### Clean up everything
```bash
docker-compose down -v --rmi all
```

## WSL-Specific Notes

### GPU Passthrough
WSL2 supports GPU passthrough for NVIDIA GPUs. Ensure:
1. WSL2 (not WSL1): `wsl --status`
2. NVIDIA drivers installed on Windows host
3. NVIDIA Container Toolkit installed in WSL
4. Docker Desktop configured for WSL2 backend

### File System Performance
Use native Linux file system for better performance:
- Good: `/home/user/projects/` (inside WSL)
- Slow: `/mnt/c/Users/...` (Windows file system)

### Memory Limits
WSL2 uses dynamic memory allocation. Configure in `.wslconfig`:
```ini
[wsl2]
memory=8GB
processors=4
swap=2GB
```

## Additional Resources

- **SHARP Model**: https://github.com/apple/ml-sharp
- **PlayCanvas**: https://playcanvas.com/
- **WebXR**: https://immersiveweb.dev/
- **Docker GPU Support**: https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/
