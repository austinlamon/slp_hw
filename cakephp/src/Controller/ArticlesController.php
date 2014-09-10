namespace App\Controller;

class ArticlesController extends AppController {

    public function index() {
        $articles = $this->Articles->find('all');
        $this->set(compact('articles'));
    }
    
    public function view($id = null) {
        if (!$id) {
            throw new NotFoundException(__('Invalid article'));
        }
        $article = $this->Articles->get($id);
        $this->set(compact('article'));
    }
}