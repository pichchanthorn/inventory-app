@echo off
docker-compose up -d
echo.
echo Waiting for MySQL to finish initializing on first run (may take ~20s)...
timeout 20
echo App running at http://localhost:9091
pause
