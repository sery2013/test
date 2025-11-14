import requests
import json
import time
import logging
import os
from datetime import datetime, timedelta, timezone # Импортируем для работы с датами
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s: %(message)s")
API_KEY = os.getenv("API_KEY")
COMMUNITY_ID = "1951903018464772103"
BASE_URL = f"https://api.socialdata.tools/twitter/community/{COMMUNITY_ID}/tweets"
HEADERS = {"Authorization": f"Bearer {API_KEY}"}
TWEETS_FILE = "all_tweets.json"
LEADERBOARD_FILE = "leaderboard.json"
def is_within_last_n_days(created_at_str, days=60):
    """
    Проверяет, была ли дата создания твита (в формате ISO 8601) в течение последних N дней.
    """
    # API возвращает дату в формате ISO 8601, например: "2025-04-01T12:34:56.000Z"
    try:
        tweet_time = datetime.fromisoformat(created_at_str.replace("Z", "+00:00"))
    except ValueError:
        # Если формат даты неправильный, считаем, что твит "старый"
        logging.warning(f"Неверный формат даты: {created_at_str}")
        return False
    now = datetime.now(timezone.utc)
    n_days_ago = now - timedelta(days=days)
    # Сравниваем timestamp
    return tweet_time >= n_days_ago
def load_json(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    except FileNotFoundError:
        return []
def save_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
def fetch_tweets(cursor=None, limit=50):
    params = {"type": "Latest", "limit": limit}
    if cursor:
        params["cursor"] = cursor
    r = requests.get(BASE_URL, headers=HEADERS, params=params)
    r.raise_for_status()
    return r.json()
def collect_all_tweets():
    all_tweets = []
    seen_ids = set()
    cursor = None
    total_new = 0
    while True:
        data = fetch_tweets(cursor)
        tweets = data.get("tweets", [])
        cursor = data.get("next_cursor")
        if not tweets:
            break
        # --- ИЗМЕНЕНИЕ: Фильтрация по дате (теперь за последние 60 дней) ---
        new_tweets = [t for t in tweets if t["id_str"] not in seen_ids and is_within_last_n_days(t.get("created_at"), days=60)]
        # --- КОНЕЦ ИЗМЕНЕНИЯ ---
        if not new_tweets:
            logging.info("Достигнуты твиты за пределами последних 60 дней, остановка сбора.")
            break
        all_tweets.extend(new_tweets)
        seen_ids.update(t["id_str"] for t in new_tweets)
        total_new += len(new_tweets)
        logging.info(f"✅ Загружено {len(new_tweets)} новых твитов за последние 60 дней (всего: {len(all_tweets)})")
        if not cursor:
            break
        time.sleep(3)
    # --- ИЗМЕНЕНИЕ: Сохраняем ТОЛЬКО твиты за последние 60 дней ---
    save_json(TWEETS_FILE, all_tweets)
    # --- КОНЕЦ ИЗМЕНЕНИЯ ---
    logging.info(f"Сбор завершён. Всего твитов за последние 60 дней: {len(all_tweets)}") # <-- ИСПРАВЛЕНО
    return all_tweets
def build_leaderboard(tweets):
    leaderboard = {}
    for t in tweets: # Обрабатываем только твиты, переданные в функцию (т.е. за последние 60 дней)
        user = t.get("user")
        if not user:
            continue
        name = user.get("screen_name")
        if not name:
            continue
        stats = leaderboard.setdefault(name, {
            "posts": 0,
            "likes": 0,
            "retweets": 0,
            "comments": 0,
            "quotes": 0,
            "views": 0
        })
        stats["posts"] += 1
        stats["likes"] += t.get("favorite_count", 0)
        stats["retweets"] += t.get("retweet_count", 0)
        stats["comments"] += t.get("reply_count", 0)
        stats["quotes"] += t.get("quote_count", 0)
        stats["views"] += t.get("views_count", 0)
    leaderboard_list = [[user, stats] for user, stats in leaderboard.items()]
    save_json(LEADERBOARD_FILE, leaderboard_list)
    logging.info(f"🏆 Лидерборд обновлён ({len(leaderboard_list)} участников за последние 60 дней).")
# --- НОВЫЙ БЛОК: СОЗДАНИЕ ДАННЫХ ДЛЯ ГРАФИКА ---
def build_daily_stats(tweets):
    """
    Собирает статистику по дням: сколько постов было опубликовано в каждый день (за последние 60 дней).
    """
    daily_stats = {}
    for t in tweets:
        created_at_str = t.get("created_at")
        if not created_at_str:
            continue
        # Парсим дату
        try:
            tweet_date = datetime.fromisoformat(created_at_str.replace("Z", "+00:00")).date()
        except ValueError:
            logging.warning(f"Неверный формат даты: {created_at_str}")
            continue
        # Увеличиваем счётчик для этого дня
        daily_stats[tweet_date] = daily_stats.get(tweet_date, 0) + 1
    # Преобразуем в список для удобства (дата -> количество)
    daily_list = [{"date": str(date), "posts": count} for date, count in sorted(daily_stats.items())]
    # Сохраняем в файл
    save_json("daily_posts.json", daily_list)
    logging.info(f"📊 График активности обновлён ({len(daily_list)} дней за последние 60 дней).")
if __name__ == "__main__":
    tweets = collect_all_tweets()
    build_leaderboard(tweets)
    build_daily_stats(tweets)  # Запускаем новую функцию
