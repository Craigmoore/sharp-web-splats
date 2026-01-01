# Multi-stage build for SHARP Web Splats
# Supports both CPU and GPU modes (use docker-compose for GPU runtime)

FROM python:3.13-slim AS base

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Image processing libraries
    ffmpeg \
    libjpeg-dev \
    libpng-dev \
    zlib1g-dev \
    libheif-dev \
    # Build tools
    build-essential \
    git \
    curl \
    # Clean up
    && rm -rf /var/lib/apt/lists/*

# Install Node.js (v20 LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy package files for dependency installation
COPY package.json package-lock.json* ./
RUN npm install

# Install splat-transform globally for PLY compression
RUN npm install -g @playcanvas/splat-transform

# Copy Python requirements
COPY requirements.txt ./

# Copy ml-sharp submodule first (required by requirements.txt)
# Note: Run 'git submodule update --init --recursive' before building
COPY ml-sharp ./ml-sharp

# Install Python dependencies (includes ml-sharp via -e ./ml-sharp)
RUN pip install --no-cache-dir --upgrade pip && \
    pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY app.py ./
COPY templates ./templates
COPY static ./static

# Create directories for runtime data
RUN mkdir -p /app/static/samples && \
    mkdir -p /root/.cache/torch/hub/checkpoints

# Expose port
EXPOSE 8080

# Environment variables (can be overridden)
ENV HOST=0.0.0.0
ENV PORT=8080
ENV PYTHONUNBUFFERED=1

# Health check
# Note: Uses curl with -k flag to handle self-signed SSL certificates
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -kf https://localhost:8080/health || exit 1

# Run the application
CMD ["python", "app.py"]
