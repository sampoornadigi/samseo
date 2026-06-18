# Control-plane dev image. Source is bind-mounted by docker-compose; this image
# just provides Node 20 and installs dependencies into the image layer.
FROM node:20-alpine

WORKDIR /app

# Install deps first for layer caching. The bind mount overlays /app at runtime,
# but node_modules is kept on a named volume (see docker-compose) so it persists.
COPY package.json ./
RUN npm install --no-audit --no-fund

COPY . .

EXPOSE 3000
CMD ["npm", "run", "dev"]
